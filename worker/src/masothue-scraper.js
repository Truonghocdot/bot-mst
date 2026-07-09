const fs = require('fs');
const path = require('path');
const { chromium, firefox, webkit } = require('playwright-extra');
const stealthPlugin = require('puppeteer-extra-plugin-stealth');
const config = require('./config');
const logger = require('./logger');

chromium.use(stealthPlugin());

const CLOUDFLARE_TITLE_PATTERNS = [/just a moment/i, /thuc hien xac minh bao mat/i];
const CLOUDFLARE_BODY_PATTERNS = [
  /Enable JavaScript and cookies to continue/i,
  /Thực hiện xác minh bảo mật/i,
  /Trang web này sử dụng dịch vụ bảo mật để chống bot độc hại/i,
  /Ray ID:/i,
];

function normalizePhone(value) {
  const digits = String(value || '').replace(/\D+/g, '');

  if (!digits) {
    return null;
  }

  if (digits.startsWith('0084') && digits.length >= 10) {
    return `0${digits.slice(4)}`;
  }

  if (digits.startsWith('84') && !digits.startsWith('840') && digits.length >= 10) {
    return `0${digits.slice(2)}`;
  }

  return digits;
}

function buildPhoneCandidatesFromTokens(tokens) {
  const candidates = [];
  let current = '';

  for (const token of tokens) {
    const digits = String(token || '').replace(/\D+/g, '');

    if (!digits) {
      continue;
    }

    const next = `${current}${digits}`;

    if (!current) {
      current = digits;
      continue;
    }

    if (next.length <= 11) {
      current = next;
      continue;
    }

    if (current.length >= 8) {
      candidates.push(current);
    }

    current = digits;
  }

  if (current.length >= 8) {
    candidates.push(current);
  }

  return candidates;
}

function extractPhoneCandidates(value) {
  const raw = String(value || '').trim();

  if (!raw) {
    return [];
  }

  const normalizedSeparators = raw.replace(/[|/;,]+/g, '\n');
  const segments = normalizedSeparators
    .split(/\n+/)
    .map((segment) => segment.trim())
    .filter(Boolean);
  const candidates = [];

  for (const segment of segments) {
    const compactDigits = normalizePhone(segment);

    if (compactDigits && compactDigits.length >= 8 && compactDigits.length <= 11) {
      candidates.push(compactDigits);
      continue;
    }

    const tokens = segment
      .split(/\s+/)
      .map((token) => token.trim())
      .filter(Boolean);

    candidates.push(...buildPhoneCandidatesFromTokens(tokens));
  }

  return [...new Set(
    candidates
      .map((candidate) => normalizePhone(candidate))
      .filter((candidate) => candidate && candidate.length >= 8 && candidate.length <= 11)
  )];
}

function normalizePhonePayload(value) {
  const phoneList = extractPhoneCandidates(value);

  return {
    phone: phoneList[0] || null,
    phoneRaw: String(value || '').trim() || null,
    phoneList,
    phoneSignature: phoneList.join('|') || null,
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

async function readDetail(page) {
  return page.evaluate(() => {
    const normalize = (value) => (value ? value.replace(/\s+/g, ' ').trim() : '');
    const rows = Array.from(document.querySelectorAll('table.table-taxinfo tbody tr'));
    const rowMap = new Map();

    rows.forEach((row) => {
      const cells = row.querySelectorAll('td');

      if (cells.length < 2) {
        return;
      }

      const label = normalize(cells[0].textContent || '').toLowerCase();
      const value = normalize(cells[1].textContent || '');

      if (label) {
        rowMap.set(label, value);
      }
    });

    const addresses = Array.from(document.querySelectorAll('td[itemprop="address"] .copy')).map((node) =>
      normalize(node.textContent || '')
    );

    return {
      company_name: normalize(document.querySelector('table.table-taxinfo thead th[itemprop="name"]')?.textContent || ''),
      tax_code: normalize(document.querySelector('td[itemprop="taxID"] .copy')?.textContent || ''),
      tax_address: normalize(document.querySelector('#tax-address-html')?.textContent || rowMap.get('địa chỉ thuế') || ''),
      registered_address: addresses[1] || addresses[0] || '',
      phone: normalize(document.querySelector('#tel-full')?.textContent || rowMap.get('điện thoại') || ''),
      tax_status: rowMap.get('tình trạng') || '',
      international_name: rowMap.get('tên quốc tế') || '',
      legal_representative: rowMap.get('người đại diện') || '',
      active_date: rowMap.get('ngày hoạt động') || '',
      managed_by: rowMap.get('quản lý bởi') || '',
      company_type: rowMap.get('loại hình dn') || '',
      main_business: rowMap.get('ngành nghề chính') || '',
    };
  });
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

async function waitForTableTaxInfo(page, detailUrl) {
  const deadline = Date.now() + config.navigationTimeoutMs;
  let challengeLogged = false;

  while (Date.now() < deadline) {
    if (await page.locator('table.table-taxinfo').count()) {
      if (challengeLogged) {
        logger.info('Cloudflare challenge cleared on detail page.', {
          detailUrl,
        }, 'worker.cloudflare_cleared_detail');
      }

      return;
    }

    if (await isCloudflareChallenge(page)) {
      if (!challengeLogged) {
        const challenge = await readChallengeDetails(page);

        logger.warn('Cloudflare challenge detected on detail page.', {
          detailUrl,
          title: challenge.title,
          rayId: challenge.rayId,
          hasTurnstile: challenge.hasTurnstile,
          hasChallengePlatform: challenge.hasChallengePlatform,
          url: challenge.url,
        }, 'worker.cloudflare_detail');

        challengeLogged = true;
      }

      await page.waitForTimeout(3000);
      continue;
    }

    await page.waitForTimeout(1000);
  }

  throw new Error(`Detail page did not load before timeout: ${detailUrl}`);
}

async function waitForListingContent(page) {
  await page.goto(config.targetUrl, { waitUntil: 'domcontentloaded' });

  const deadline = Date.now() + config.navigationTimeoutMs;
  let challengeLogged = false;

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

      await page.waitForTimeout(3000);
      continue;
    }

    await page.waitForTimeout(1000);
  }

  if (await isCloudflareChallenge(page)) {
    throw new Error(
      'Cloudflare challenge is blocking the worker. Try PLAYWRIGHT_HEADLESS=false once to solve it and persist PLAYWRIGHT_STORAGE_STATE.'
    );
  }

  throw new Error('Listing content did not load before timeout.');
}

async function extractListingItems(page) {
  await waitForListingContent(page);

  const items = await readListingItems(page);

  return items.filter((item) => item.detail_url && item.tax_code);
}

async function extractDetail(detailPage, listingItem) {
  await detailPage.goto(listingItem.detail_url, { waitUntil: 'domcontentloaded' });
  await waitForTableTaxInfo(detailPage, listingItem.detail_url);

  const detail = await readDetail(detailPage);
  const phoneData = normalizePhonePayload(detail.phone);

  return {
    ...listingItem,
    ...detail,
    phone: phoneData.phone,
    phone_raw: phoneData.phoneRaw,
    phone_list: phoneData.phoneList,
    phone_signature: phoneData.phoneSignature,
    observed_at: new Date().toISOString(),
    source_url: config.targetUrl,
    raw_payload: {
      listing: listingItem,
      detail,
    },
  };
}

async function scrapeMasothueBatch() {
  const browserType = browserTypeFor(config.browser);
  const browser = await launchBrowser(browserType);
  const storageStatePath = path.resolve(__dirname, '..', config.storageStatePath);
  const hasStorageState = fs.existsSync(storageStatePath);
  const context = await browser.newContext(createContextOptions(storageStatePath, hasStorageState));
  const listPage = await context.newPage();
  const detailPage = await context.newPage();

  listPage.setDefaultTimeout(config.timeoutMs);
  detailPage.setDefaultTimeout(config.timeoutMs);
  listPage.setDefaultNavigationTimeout(config.navigationTimeoutMs);
  detailPage.setDefaultNavigationTimeout(config.navigationTimeoutMs);

  await blockHeavyAssets(context);

  try {
    const listingItems = await extractListingItems(listPage);
    const limitedItems = listingItems.slice(0, config.maxItemsPerRun);
    const results = [];

    fs.mkdirSync(path.dirname(storageStatePath), { recursive: true });
    await context.storageState({ path: storageStatePath });

    for (const item of limitedItems) {
      results.push(await extractDetail(detailPage, item));
    }

    return results;
  } finally {
    await context.close();
    await browser.close();
  }
}

module.exports = {
  extractListingItems,
  extractDetail,
  extractPhoneCandidates,
  normalizePhone,
  normalizePhonePayload,
  readDetail,
  readListingItems,
  scrapeMasothueBatch,
};
