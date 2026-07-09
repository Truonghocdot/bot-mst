const https = require('https');
const http = require('http');
const { URL } = require('url');
const config = require('./config');
const logger = require('./logger');

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
      timeout: config.capsolverTimeoutMs,
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
      reject(new Error(`CapSolver: request timeout sau ${config.capsolverTimeoutMs}ms`));
    });

    req.write(payload);
    req.end();
  });
}

/**
 * Tạo định dạng proxy string cho CapSolver từ config.
 * CapSolver chấp nhận: "ip:port:user:pass" hoặc URL đầy đủ.
 * Nếu config.capsolverProxy đã được đặt, dùng trực tiếp.
 * Ngược lại, ghép từ proxy config Playwright.
 *
 * @returns {string|null}
 */
function buildCapsolverProxy() {
  if (config.capsolverProxy) {
    // Chuyển URL proxy thành định dạng "ip:port:user:pass"
    try {
      const parsedProxy = new URL(config.capsolverProxy);
      const host = parsedProxy.hostname;
      const port = parsedProxy.port || 80;
      const user = parsedProxy.username ? decodeURIComponent(parsedProxy.username) : '';
      const pass = parsedProxy.password ? decodeURIComponent(parsedProxy.password) : '';

      if (user && pass) {
        return `${host}:${port}:${user}:${pass}`;
      }

      return `${host}:${port}`;
    } catch {
      // Không phải URL hợp lệ — trả về nguyên bản
      return config.capsolverProxy;
    }
  }

  if (config.proxyEnabled && config.proxyServer) {
    try {
      const parsedProxy = new URL(config.proxyServer);
      const host = parsedProxy.hostname;
      const port = parsedProxy.port || 80;
      const user = config.proxyUsername || '';
      const pass = config.proxyPassword || '';

      if (user && pass) {
        return `${host}:${port}:${user}:${pass}`;
      }

      return `${host}:${port}`;
    } catch {
      return null;
    }
  }

  return null;
}

/**
 * Tạo task AntiCloudflareTask trên CapSolver.
 *
 * @param {object} params
 * @param {string} params.websiteUrl - URL trang đích cần bypass
 * @param {string|null} [params.html] - HTML của trang challenge (tùy chọn nhưng nên có)
 * @returns {Promise<string>} taskId
 */
async function createTask({ websiteUrl, html = null }) {
  const task = {
    type: config.capsolverTaskType,
    websiteURL: websiteUrl,
  };

  const proxy = buildCapsolverProxy();

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
    throw new Error(
      `CapSolver tạo task thất bại: [${response.errorCode}] ${response.errorDescription}`
    );
  }

  logger.info('CapSolver: task đã tạo thành công.', {
    taskId: response.taskId,
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
 * Trả về solution object chứa cookies.cf_clearance và userAgent.
 *
 * @param {object} params
 * @param {string} params.websiteUrl
 * @param {string|null} [params.html]
 * @returns {Promise<{ cfClearance: string|null, userAgent: string|null, cookies: object }>}
 */
async function solveCloudflareChallenge({ websiteUrl, html = null }) {
  if (!config.capsolverApiKey) {
    throw new Error('CAPSOLVER_API_KEY chưa được cấu hình.');
  }

  const taskId = await createTask({ websiteUrl, html });
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
}

module.exports = {
  solveCloudflareChallenge,
  buildCapsolverProxy,
};
