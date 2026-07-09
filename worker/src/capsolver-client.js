const https = require('https');
const http = require('http');
const { URL } = require('url');
const config = require('./config');
const logger = require('./logger');

// Mã lỗi CapSolver liên quan đến proxy để có thể retry với proxy khác
const PROXY_RETRYABLE_ERRORS = new Set([
  'ERROR_PROXY_CONNECT_REFUSED',
  'ERROR_PROXY_NOT_AUTHORISED',
  'ERROR_PROXY_TIMEOUT',
  'ERROR_PROXY_FORMAT_INVALID',
]);

/**
 * Gọi một HTTP/HTTPS endpoint với JSON body, trả về parsed response JSON.
 * Không dùng thư viện thứ ba — chỉ dùng Node built-ins.
 *
 * @param {string} url
 * @param {object} body
 * @returns {Promise<object>}
 */
function postJson(url, body) {
  return new Promise((resolve, reject) => {
    const parsedUrl = new URL(url);
    const isHttps = parsedUrl.protocol === 'https:';
    const transport = isHttps ? https : http;
    const payload = JSON.stringify(body);

    const options = {
      hostname: parsedUrl.hostname,
      port: parsedUrl.port || (isHttps ? 443 : 80),
      path: parsedUrl.pathname + parsedUrl.search,
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(payload),
      },
      timeout: 30000, // timeout riêng cho mỗi HTTP request tới CapSolver API
    };

    const req = transport.request(options, (res) => {
      let data = '';

      res.on('data', (chunk) => {
        data += chunk;
      });

      res.on('end', () => {
        try {
          resolve(JSON.parse(data));
        } catch (err) {
          reject(new Error(`CapSolver: phân tích JSON thất bại — ${err.message}. Body: ${data.slice(0, 200)}`));
        }
      });
    });

    req.on('error', reject);
    req.on('timeout', () => {
      req.destroy();
      reject(new Error('CapSolver: HTTP request timeout (30s)'));
    });

    req.write(payload);
    req.end();
  });
}

/**
 * Chuyển proxy URL (http://user:pass@host:port) sang định dạng CapSolver: "host:port:user:pass".
 *
 * @param {string} rawProxy - URL proxy đầy đủ
 * @param {string} [username] - username override nếu không nằm trong URL
 * @param {string} [password] - password override nếu không nằm trong URL
 * @returns {string|null}
 */
function parseProxyToCapsolverFormat(rawProxy, username = '', password = '') {
  if (!rawProxy) return null;

  try {
    const parsed = new URL(rawProxy);
    const host = parsed.hostname;
    const port = parsed.port || 80;
    const user = parsed.username ? decodeURIComponent(parsed.username) : username;
    const pass = parsed.password ? decodeURIComponent(parsed.password) : password;

    if (user && pass) {
      return `${host}:${port}:${user}:${pass}`;
    }

    return `${host}:${port}`;
  } catch {
    // Không phải URL hợp lệ — trả về nguyên bản (có thể đã ở định dạng host:port:user:pass)
    return rawProxy;
  }
}

/**
 * Xây dựng danh sách proxy để thử cho CapSolver theo thứ tự ưu tiên:
 * 1. CAPSOLVER_PROXY (explicit)
 * 2. PROXY_SERVER (primary Playwright proxy)
 * 3. PROXY_FALLBACK_SERVER (fallback Playwright proxy)
 *
 * Trả về mảng các proxy string ở định dạng "host:port:user:pass".
 * Mảng rỗng nếu không có proxy nào được cấu hình.
 *
 * @returns {string[]}
 */
function buildCapsolverProxyList() {
  const list = [];
  const user = config.proxyUsername || '';
  const pass = config.proxyPassword || '';

  // Ưu tiên 1: CAPSOLVER_PROXY explicit
  if (config.capsolverProxy) {
    const formatted = parseProxyToCapsolverFormat(config.capsolverProxy, user, pass);

    if (formatted) {
      list.push(formatted);
    }
  }

  if (!config.proxyEnabled) {
    return list;
  }

  // Ưu tiên 2: PROXY_SERVER (primary)
  if (config.proxyServer) {
    const formatted = parseProxyToCapsolverFormat(config.proxyServer, user, pass);

    if (formatted && !list.includes(formatted)) {
      list.push(formatted);
    }
  }

  // Ưu tiên 3: PROXY_FALLBACK_SERVER
  if (config.proxyFallbackServer) {
    const formatted = parseProxyToCapsolverFormat(config.proxyFallbackServer, user, pass);

    if (formatted && !list.includes(formatted)) {
      list.push(formatted);
    }
  }

  return list;
}

/**
 * Tạo task AntiCloudflareTask trên CapSolver với proxy đã chỉ định.
 *
 * @param {object} params
 * @param {string} params.websiteUrl - URL trang đích cần bypass
 * @param {string|null} [params.html] - HTML của trang challenge
 * @param {string|null} [params.proxy] - Proxy string ở định dạng "host:port:user:pass"
 * @returns {Promise<string>} taskId
 */
async function createTask({ websiteUrl, html = null, proxy = null }) {
  const task = {
    type: config.capsolverTaskType,
    websiteURL: websiteUrl,
  };

  if (proxy) {
    task.proxy = proxy;
  }

  const userAgent = config.capsolverUserAgent || config.userAgent;

  if (userAgent) {
    task.userAgent = userAgent;
  }

  if (config.capsolverSubmitHtml && html) {
    task.html = html;
  }

  const requestBody = {
    clientKey: config.capsolverApiKey,
    task,
  };

  logger.info('CapSolver: đang tạo task AntiCloudflareTask.', {
    websiteUrl,
    proxy: proxy || '(không có)',
    hasHtml: Boolean(html),
  }, 'capsolver.create_task');

  const response = await postJson(config.capsolverCreateTaskUrl, requestBody);

  if (response.errorId !== 0) {
    const err = new Error(
      `CapSolver tạo task thất bại: [${response.errorCode}] ${response.errorDescription}`
    );

    // Gắn errorCode để caller có thể kiểm tra và retry với proxy khác
    err.capsolverErrorCode = response.errorCode;
    throw err;
  }

  logger.info('CapSolver: task đã tạo thành công.', {
    taskId: response.taskId,
    proxy: proxy || '(không có)',
  }, 'capsolver.task_created');

  return response.taskId;
}

/**
 * Truy vấn kết quả task cho đến khi thành công hoặc hết timeout.
 *
 * @param {string} taskId
 * @returns {Promise<object>} solution object từ CapSolver
 */
async function pollTaskResult(taskId) {
  const deadline = Date.now() + config.capsolverTimeoutMs;
  const pollInterval = config.capsolverPollIntervalMs;
  let attempt = 0;

  while (Date.now() < deadline) {
    attempt += 1;

    const response = await postJson(config.capsolverGetTaskResultUrl, {
      clientKey: config.capsolverApiKey,
      taskId,
    });

    if (response.errorId !== 0) {
      throw new Error(
        `CapSolver getTaskResult thất bại: [${response.errorCode}] ${response.errorDescription}`
      );
    }

    if (response.status === 'ready') {
      logger.info('CapSolver: nhận được kết quả giải challenge.', {
        taskId,
        attempt,
        hasCfClearance: Boolean(response.solution?.cookies?.cf_clearance),
        hasToken: Boolean(response.solution?.token),
      }, 'capsolver.task_ready');

      return response.solution;
    }

    if (response.status === 'failed') {
      throw new Error(`CapSolver task thất bại: taskId=${taskId}, attempt=${attempt}`);
    }

    // status === 'processing' — tiếp tục chờ
    logger.info(`CapSolver: task đang xử lý, thử lại sau ${pollInterval}ms.`, {
      taskId,
      attempt,
      status: response.status,
    }, 'capsolver.task_processing');

    await new Promise((resolve) => setTimeout(resolve, pollInterval));
  }

  throw new Error(`CapSolver: hết timeout (${config.capsolverTimeoutMs}ms) chờ kết quả — taskId=${taskId}`);
}

/**
 * Giải Cloudflare Challenge cho URL đã cho.
 * Tự động thử fallback proxy nếu primary proxy bị từ chối.
 *
 * @param {object} params
 * @param {string} params.websiteUrl
 * @param {string|null} [params.html]
 * @returns {Promise<{ cfClearance: string|null, userAgent: string|null, cookies: object, taskId: string }>}
 */
async function solveCloudflareChallenge({ websiteUrl, html = null }) {
  if (!config.capsolverApiKey) {
    throw new Error('CAPSOLVER_API_KEY chưa được cấu hình.');
  }

  const proxyList = buildCapsolverProxyList();
  // Luôn thêm slot "không proxy" vào cuối làm last resort
  const proxiesToTry = proxyList.length > 0 ? [...proxyList, null] : [null];
  let lastError;

  for (let i = 0; i < proxiesToTry.length; i++) {
    const proxy = proxiesToTry[i];
    const isRetry = i > 0;

    if (isRetry) {
      logger.warn('CapSolver: thử lại với proxy khác.', {
        websiteUrl,
        attempt: i + 1,
        proxy: proxy || '(không proxy)',
        previousError: lastError?.message,
      }, 'capsolver.proxy_retry');
    }

    try {
      const taskId = await createTask({ websiteUrl, html, proxy });
      const solution = await pollTaskResult(taskId);

      const cfClearance = solution?.cookies?.cf_clearance || null;
      const solvedUserAgent = solution?.userAgent || null;
      const allCookies = solution?.cookies || {};

      if (!cfClearance) {
        logger.warn('CapSolver: không tìm thấy cf_clearance trong solution.', {
          taskId,
          solutionKeys: Object.keys(solution || {}),
        }, 'capsolver.no_cf_clearance');
      }

      return {
        cfClearance,
        userAgent: solvedUserAgent,
        cookies: allCookies,
        taskId,
      };
    } catch (err) {
      lastError = err;

      // Nếu là lỗi proxy — thử proxy tiếp theo trong danh sách
      if (PROXY_RETRYABLE_ERRORS.has(err.capsolverErrorCode)) {
        logger.warn(`CapSolver: proxy bị từ chối [${err.capsolverErrorCode}], chuyển proxy khác.`, {
          websiteUrl,
          proxy: proxy || '(không proxy)',
          proxyErrorCode: err.capsolverErrorCode,
          remainingProxies: proxiesToTry.length - i - 1,
        }, 'capsolver.proxy_refused');
        continue;
      }

      // Lỗi khác (network, timeout, API) — throw ngay
      throw err;
    }
  }

  // Tất cả proxy đều thất bại
  throw new Error(
    `CapSolver: tất cả ${proxiesToTry.length} proxy đều thất bại — ${lastError?.message}`
  );
}

module.exports = {
  solveCloudflareChallenge,
  buildCapsolverProxyList,
};
