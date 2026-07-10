const fs = require('fs');
const path = require('path');
const { chromium, firefox, webkit } = require('playwright-extra');
const stealthPlugin = require('puppeteer-extra-plugin-stealth');
const config = require('./config');
const logger = require('./logger');
const { solveCloudflareChallenge } = require('./capsolver-client'); // buildCapsolverProxyList dùng nội bộ trong capsolver-client

chromium.use(stealthPlugin());

const CLOUDFLARE_TITLE_PATTERNS = [/just a moment/i, /thuc hien xac minh bao mat/i];
const CLOUDFLARE_BODY_PATTERNS = [
  /Enable JavaScript and cookies to continue/i,
  /Thực hiện xác minh bảo mật/i,
  /Trang web này sử dụng dịch vụ bảo mật để chống bot độc hại/i,
  /Ray ID:/i,
];

function sanitizeArtifactSegment(value) {
  return String(value || '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 80) || 'artifact';
}

async function capturePageArtifacts(page, reason, extra = {}) {
  const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
  const artifactName = `${timestamp}-${sanitizeArtifactSegment(reason)}`;
  const artifactDir = path.resolve(__dirname, '..', config.debugArtifactsDir);
  const screenshotPath = path.join(artifactDir, `${artifactName}.png`);
  const htmlPath = path.join(artifactDir, `${artifactName}.html`);
  const textPath = path.join(artifactDir, `${artifactName}.txt`);
  const metadataPath = path.join(artifactDir, `${artifactName}.json`);

  fs.mkdirSync(artifactDir, { recursive: true });

  const title = await page.title().catch(() => '');
  const url = page.url();
  const html = await page.content().catch(() => '');
  const bodyText = await page.locator('body').innerText().catch(() => '');
  const listingCount = await page.locator('.tax-listing div[data-prefetch]').count().catch(() => -1);

  await Promise.allSettled([
    page.screenshot({
      path: screenshotPath,
      fullPage: true,
    }),
    fs.promises.writeFile(htmlPath, html, 'utf8'),
    fs.promises.writeFile(textPath, bodyText, 'utf8'),
    fs.promises.writeFile(metadataPath, JSON.stringify({
      reason,
      captured_at: new Date().toISOString(),
      title,
      url,
      listing_count: listingCount,
      body_snippet: bodyText.slice(0, 1000),
      html_snippet: html.slice(0, 1000),
      ...extra,
    }, null, 2)),
  ]);

  logger.error('Captured page artifacts for debugging.', {
    reason,
    title,
    url,
    listingCount,
    screenshotPath,
    htmlPath,
    textPath,
    metadataPath,
  }, 'worker.debug_artifacts');

  return {
    screenshotPath,
    htmlPath,
    textPath,
    metadataPath,
  };
}

function browserTypeFor(name) {
  const mapping = { chromium, firefox, webkit };
  const browserType = mapping[name];

  if (!browserType) {
    throw new Error(`Unsupported browser: ${name}`);
  }

  return browserType;
}

function buildListingRequestUrl() {
  if (!config.disablePageCache) {
    return config.targetUrl;
  }

  const url = new URL(config.targetUrl);

  url.searchParams.set('_cb', `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`);

  return url.toString();
}

function createLaunchOptions(proxyServer = config.proxyServer) {
  const launchOptions = {
    headless: config.headless,
  };

  if (config.proxyEnabled && proxyServer) {
    launchOptions.proxy = {
      server: proxyServer,
    };

    if (config.proxyUsername) {
      launchOptions.proxy.username = config.proxyUsername;
    }

    if (config.proxyPassword) {
      launchOptions.proxy.password = config.proxyPassword;
    }
  }

  return launchOptions;
}

async function launchBrowser(browserType) {
  if (!config.proxyEnabled) {
    return browserType.launch(createLaunchOptions());
  }

  const proxyServers = [config.proxyServer, config.proxyFallbackServer].filter(Boolean);
  let lastError;

  for (const proxyServer of proxyServers) {
    try {
      logger.info('Launching browser with proxy.', {
        proxyServer,
        proxyType: config.proxyType,
      }, 'worker.proxy_launch');

      return await browserType.launch(createLaunchOptions(proxyServer));
    } catch (error) {
      lastError = error;
      logger.warn('Failed to launch browser with proxy.', {
        proxyServer,
        error: error.message,
      }, 'worker.proxy_launch_failed');
    }
  }

  throw lastError || new Error('Failed to launch browser with configured proxy.');
}

function createContextOptions(storageStatePath, hasStorageState) {
  const contextOptions = {
    locale: config.locale,
    timezoneId: config.timezoneId,
    userAgent: config.userAgent,
    extraHTTPHeaders: config.disablePageCache
      ? {
        'Cache-Control': 'no-cache, no-store, max-age=0',
        Pragma: 'no-cache',
      }
      : undefined,
    viewport: {
      width: config.viewportWidth,
      height: config.viewportHeight,
    },
  };

  if (hasStorageState) {
    contextOptions.storageState = storageStatePath;
  }

  return contextOptions;
}

async function blockHeavyAssets(context) {
  await context.route('**/*', async (route) => {
    const resourceType = route.request().resourceType();

    if (['image', 'font', 'media'].includes(resourceType)) {
      await route.abort();
      return;
    }

    await route.continue();
  });
}

async function readListingItems(page) {
  return page.evaluate((siteBaseUrl) => {
    const normalize = (value) => (value ? value.replace(/\s+/g, ' ').trim() : '');
    const cards = Array.from(document.querySelectorAll('.tax-listing div[data-prefetch]'));

    return cards.map((card, index) => {
      const headingLink = card.querySelector('h3 a[href]');
      const taxLink = Array.from(card.querySelectorAll('a[href]')).find((link) =>
        /^\d+(?:-\d+)?$/.test(normalize(link.textContent || ''))
      );
      const legalRepresentativeLink = card.querySelector('em a[href]');
      const detailPath = headingLink?.getAttribute('href') || card.getAttribute('data-prefetch') || '';

      return {
        listing_position: index + 1,
        company_name: normalize(headingLink?.textContent || ''),
        detail_url: new URL(detailPath, siteBaseUrl).toString(),
        detail_path: detailPath,
        tax_code: normalize(taxLink?.textContent || ''),
        legal_representative: normalize(legalRepresentativeLink?.textContent || ''),
        listed_address: normalize(card.querySelector('address')?.textContent || ''),
      };
    });
  }, config.siteBaseUrl);
}

async function isCloudflareChallenge(page) {
  const title = await page.title().catch(() => '');
  const normalizedTitle = title.normalize('NFD').replace(/[\u0300-\u036f]/g, '');

  if (CLOUDFLARE_TITLE_PATTERNS.some((pattern) => pattern.test(title) || pattern.test(normalizedTitle))) {
    return true;
  }

  const body = await page.locator('body').innerText().catch(() => '');
  const normalizedBody = body.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  const html = await page.content().catch(() => '');

  if (CLOUDFLARE_BODY_PATTERNS.some((pattern) => pattern.test(body) || pattern.test(normalizedBody))) {
    return true;
  }

  return /challenge-platform|chl_page|_cf_chl_opt|cf-browser-verification|cf_challenge/i.test(html);
}

async function readChallengeDetails(page) {
  const title = await page.title().catch(() => '');
  const url = page.url();
  const html = await page.content().catch(() => '');
  const body = await page.locator('body').innerText().catch(() => '');
  const rayIdMatch = body.match(/Ray ID:\s*([a-z0-9]+)/i) || html.match(/cRay:\s*'([^']+)'/i);

  return {
    title,
    url,
    rayId: rayIdMatch ? rayIdMatch[1] : null,
    hasTurnstile: /turnstile|cf-turnstile/i.test(html),
    hasChallengePlatform: /challenge-platform|chl_page|_cf_chl_opt/i.test(html),
    bodySnippet: body.slice(0, 300),
  };
}

/**
 * Inject cf_clearance cookie và userAgent vào browser context sau khi CapSolver giải thành công.
 *
 * @param {import('playwright').BrowserContext} context
 * @param {object} solution - Kết quả từ solveCloudflareChallenge
 * @param {string} targetUrl - URL để xác định domain cho cookie
 */
async function injectCfClearance(context, solution, targetUrl) {
  const { cfClearance, userAgent, cookies } = solution;

  if (!cfClearance) {
    logger.warn('CapSolver: không có cf_clearance để inject.', {
      targetUrl,
      availableCookies: Object.keys(cookies),
    }, 'capsolver.inject_skip');
    return;
  }

  const parsedUrl = new URL(targetUrl);
  const cookieDomain = parsedUrl.hostname; // e.g. masothue.com

  // Xây dựng danh sách tất cả cookies từ solution để inject vào context
  const cookieList = Object.entries(cookies).map(([name, value]) => ({
    name,
    value: String(value),
    domain: cookieDomain,
    path: '/',
    httpOnly: name === 'cf_clearance',
    secure: parsedUrl.protocol === 'https:',
    sameSite: 'Lax',
  }));

  await context.addCookies(cookieList);

  logger.info('CapSolver: đã inject cookies vào browser context.', {
    domain: cookieDomain,
    cookies: cookieList.map((c) => c.name),
    hasCfClearance: Boolean(cfClearance),
  }, 'capsolver.inject_done');

  // Nếu CapSolver trả về userAgent khác, lưu lại để dùng cho context tiếp theo
  // (Playwright không cho phép thay đổi userAgent của context đang chạy,
  //  nhưng log lại để debug nếu cần)
  if (userAgent && userAgent !== config.userAgent) {
    logger.warn('CapSolver: userAgent từ solution khác với config hiện tại.', {
      configUserAgent: config.userAgent,
      capsolverUserAgent: userAgent,
    }, 'capsolver.useragent_mismatch');
  }
}

/**
 * Giải Cloudflare challenge bằng CapSolver và inject cf_clearance vào context.
 * Sau đó reload trang để vượt qua challenge.
 *
 * @param {import('playwright').Page} page
 * @param {import('playwright').BrowserContext} context
 * @param {string} targetUrl - URL trang đang bị block
 * @returns {Promise<boolean>} true nếu giải thành công
 */
async function solveChallengeWithCapsolver(page, context, targetUrl) {
  if (!config.capsolverEnabled || !config.capsolverApiKey) {
    logger.warn('CapSolver bị tắt hoặc thiếu API key — bỏ qua bypass.', {
      enabled: config.capsolverEnabled,
      hasApiKey: Boolean(config.capsolverApiKey),
    }, 'capsolver.disabled');
    return false;
  }

  const html = config.capsolverSubmitHtml
    ? await page.content().catch(() => null)
    : null;

  logger.info('CapSolver: bắt đầu giải Cloudflare challenge.', {
    targetUrl,
    hasHtml: Boolean(html),
  }, 'capsolver.solving');

  try {
    const solution = await solveCloudflareChallenge({ websiteUrl: targetUrl, html });
    await injectCfClearance(context, solution, targetUrl);

    // Reload trang để dùng cookie cf_clearance vừa inject
    await page.reload({ waitUntil: 'domcontentloaded' });
    logger.info('CapSolver: đã reload trang sau khi inject cf_clearance.', {
      targetUrl,
    }, 'capsolver.reloaded');

    return true;
  } catch (error) {
    logger.error('CapSolver: giải challenge thất bại.', {
      targetUrl,
      error: error.message,
      stack: error.stack,
    }, 'capsolver.solve_failed');
    return false;
  }
}

async function waitForListingContent(page, context) {
  const requestUrl = buildListingRequestUrl();
  const response = await page.goto(requestUrl, { waitUntil: 'domcontentloaded' });
  const responseHeaders = response ? await response.allHeaders().catch(() => ({})) : {};

  logger.info('Listing page response received.', {
    requestUrl,
    finalUrl: page.url(),
    status: response?.status?.() ?? null,
    cacheControl: responseHeaders['cache-control'] || null,
    cfCacheStatus: responseHeaders['cf-cache-status'] || null,
    age: responseHeaders.age || null,
    lastModified: responseHeaders['last-modified'] || null,
    cfRay: responseHeaders['cf-ray'] || null,
  }, 'worker.listing_response');

  // Dùng let để có thể reset deadline sau khi CapSolver hoàn thành
  let deadline = Date.now() + config.navigationTimeoutMs;
  let challengeLogged = false;
  let capsolverAttempted = false;

  while (Date.now() < deadline) {
    if (await page.locator('.tax-listing div[data-prefetch]').count()) {
      if (challengeLogged) {
        logger.info('Cloudflare challenge cleared on listing page.', {
          url: page.url(),
        }, 'worker.cloudflare_cleared_listing');
      }

      return;
    }

    if (await isCloudflareChallenge(page)) {
      if (!challengeLogged) {
        const challenge = await readChallengeDetails(page);

        logger.warn('Cloudflare challenge detected on listing page.', {
          title: challenge.title,
          rayId: challenge.rayId,
          hasTurnstile: challenge.hasTurnstile,
          hasChallengePlatform: challenge.hasChallengePlatform,
          url: challenge.url,
        }, 'worker.cloudflare_listing');

        challengeLogged = true;
      }

      // Thử giải bằng CapSolver (chỉ 1 lần)
      // CapSolver có thể mất 30–120s, reset deadline sau khi await xong.
      if (!capsolverAttempted) {
        capsolverAttempted = true;
        const solved = await solveChallengeWithCapsolver(page, context, config.targetUrl);

        // Reset deadline kể từ thời điểm CapSolver trả về kết quả
        deadline = Date.now() + config.navigationTimeoutMs;

        if (solved) {
          await page.waitForTimeout(2000);
          continue;
        }
      }

      await page.waitForTimeout(3000);
      continue;
    }

    await page.waitForTimeout(1000);
  }

  if (await isCloudflareChallenge(page)) {
    await capturePageArtifacts(page, 'listing-cloudflare-timeout', {
      targetUrl: config.targetUrl,
      challengeDetected: true,
    });

    throw new Error(
      'Cloudflare challenge is blocking the worker. Try PLAYWRIGHT_HEADLESS=false once to solve it and persist PLAYWRIGHT_STORAGE_STATE.'
    );
  }

  await capturePageArtifacts(page, 'listing-structure-timeout', {
    targetUrl: config.targetUrl,
    challengeDetected: false,
  });

  throw new Error('Listing content did not load before timeout.');
}

async function extractListingItems(page, context) {
  await waitForListingContent(page, context);

  const observedAt = new Date().toISOString();
  const items = await readListingItems(page);

  return items
    .filter((item) => item.detail_url && item.tax_code)
    .map((item) => {
      const listingPayload = { ...item };

      return {
        ...item,
        observed_at: observedAt,
        source_url: config.targetUrl,
        raw_payload: {
          listing: listingPayload,
        },
      };
    });
}

async function scrapeMasothueBatch() {
  const browserType = browserTypeFor(config.browser);
  const browser = await launchBrowser(browserType);
  const storageStatePath = path.resolve(__dirname, '..', config.storageStatePath);
  const hasStorageState = fs.existsSync(storageStatePath);
  const context = await browser.newContext(createContextOptions(storageStatePath, hasStorageState));
  const listPage = await context.newPage();

  listPage.setDefaultTimeout(config.timeoutMs);
  listPage.setDefaultNavigationTimeout(config.navigationTimeoutMs);

  await blockHeavyAssets(context);

  try {
    const listingItems = await extractListingItems(listPage, context);
    const limitedItems = config.maxItemsPerRun > 0
      ? listingItems.slice(0, config.maxItemsPerRun)
      : listingItems;
    const results = [];

    fs.mkdirSync(path.dirname(storageStatePath), { recursive: true });
    await context.storageState({ path: storageStatePath });

    results.push(...limitedItems);

    return results;
  } finally {
    await context.close();
    await browser.close();
  }
}

module.exports = {
  extractListingItems,
  readListingItems,
  scrapeMasothueBatch,
};
