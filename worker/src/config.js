const path = require('path');
const dotenv = require('dotenv');

dotenv.config({ path: path.resolve(__dirname, '../.env') });

function readEnv(name, fallback = undefined) {
  const value = process.env[name];

  if (value === undefined || value === '') {
    return fallback;
  }

  return value;
}

function readBoolean(name, fallback) {
  const value = readEnv(name);

  if (value === undefined) {
    return fallback;
  }

  return ['1', 'true', 'yes', 'on'].includes(String(value).toLowerCase());
}

function readNumber(name, fallback) {
  const value = readEnv(name);

  if (value === undefined) {
    return fallback;
  }

  const parsed = Number(value);

  if (Number.isNaN(parsed)) {
    throw new Error(`Environment variable ${name} must be a number.`);
  }

  return parsed;
}

function requireEnv(name) {
  const value = readEnv(name);

  if (!value) {
    throw new Error(`Missing required environment variable: ${name}`);
  }

  return value;
}

module.exports = {
  workerName: readEnv('WORKER_NAME', 'bot-mst-worker'),
  workerSource: readEnv('WORKER_SOURCE', 'masothue'),
  siteBaseUrl: readEnv('SITE_BASE_URL', 'https://masothue.com'),
  targetUrl: requireEnv('TARGET_URL'),
  browser: readEnv('PLAYWRIGHT_BROWSER', 'chromium'),
  headless: readBoolean('PLAYWRIGHT_HEADLESS', true),
  timeoutMs: readNumber('PLAYWRIGHT_TIMEOUT_MS', 30000),
  navigationTimeoutMs: readNumber('PLAYWRIGHT_NAVIGATION_TIMEOUT_MS', 45000),
  storageStatePath: readEnv('PLAYWRIGHT_STORAGE_STATE', '.playwright/.auth/user.json'),
  userAgent: readEnv(
    'PLAYWRIGHT_USER_AGENT',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'
  ),
  locale: readEnv('PLAYWRIGHT_LOCALE', 'vi-VN'),
  timezoneId: readEnv('PLAYWRIGHT_TIMEZONE_ID', 'Asia/Ho_Chi_Minh'),
  viewportWidth: readNumber('PLAYWRIGHT_VIEWPORT_WIDTH', 1366),
  viewportHeight: readNumber('PLAYWRIGHT_VIEWPORT_HEIGHT', 768),
  disablePageCache: readBoolean('PLAYWRIGHT_DISABLE_PAGE_CACHE', true),
  proxyEnabled: readBoolean('PROXY_ENABLED', false),
  proxyType: readEnv('PROXY_TYPE', 'sticky_residential'),
  proxyServer: readEnv('PROXY_SERVER'),
  proxyFallbackServer: readEnv('PROXY_FALLBACK_SERVER'),
  proxyUsername: readEnv('PROXY_USERNAME'),
  proxyPassword: readEnv('PROXY_PASSWORD'),
  proxySessionId: readEnv('PROXY_SESSION_ID'),
  proxyCountry: readEnv('PROXY_COUNTRY', 'VN'),
  pollIntervalMs: readNumber('POLL_INTERVAL_MS', 5000),
  maxItemsPerRun: readNumber('MAX_ITEMS_PER_RUN', 0),
  httpTimeoutMs: readNumber('HTTP_TIMEOUT_MS', 10000),
  retryAttempts: readNumber('RETRY_ATTEMPTS', 3),
  retryDelayMs: readNumber('RETRY_DELAY_MS', 2000),
  debugArtifactsDir: readEnv('PLAYWRIGHT_DEBUG_ARTIFACTS_DIR', '.playwright/debug'),
  coreApiBaseUrl: requireEnv('CORE_API_BASE_URL'),
  coreApiEndpoint: readEnv('CORE_API_ENDPOINT', '/api/ingestions/masothue'),
  coreProxyEndpoint: readEnv('CORE_PROXY_ENDPOINT', '/api/worker/proxy'),
  coreApiToken: requireEnv('CORE_API_TOKEN'),
  coreLogsEnabled: readBoolean('CORE_LOGS_ENABLED', true),
  coreLogsEndpoint: readEnv('CORE_LOGS_ENDPOINT', '/api/logs/worker'),
  coreLogsFlushIntervalMs: readNumber('CORE_LOGS_FLUSH_INTERVAL_MS', 2000),
  coreLogsBatchSize: readNumber('CORE_LOGS_BATCH_SIZE', 20),
  // CapSolver — Cloudflare Challenge bypass
  capsolverEnabled: readBoolean('CAPSOLVER_ENABLED', true),
  capsolverApiKey: readEnv('CAPSOLVER_API_KEY'),
  capsolverTaskType: readEnv('CAPSOLVER_TASK_TYPE', 'AntiCloudflareTask'),
  capsolverCreateTaskUrl: readEnv('CAPSOLVER_CREATE_TASK_URL', 'https://api.capsolver.com/createTask'),
  capsolverGetTaskResultUrl: readEnv('CAPSOLVER_GET_TASK_RESULT_URL', 'https://api.capsolver.com/getTaskResult'),
  capsolverProxy: readEnv('CAPSOLVER_PROXY'),
  capsolverUserAgent: readEnv('CAPSOLVER_USER_AGENT'),
  capsolverSubmitHtml: readBoolean('CAPSOLVER_SUBMIT_HTML', true),
  capsolverPollIntervalMs: readNumber('CAPSOLVER_POLL_INTERVAL_MS', 3000),
  capsolverTimeoutMs: readNumber('CAPSOLVER_TIMEOUT_MS', 120000),
};
