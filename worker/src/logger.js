const config = require('./config');

const logQueue = [];
let flushTimer = null;
let isFlushing = false;

function sleep(ms) {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

function serializeContext(context) {
  if (!context || typeof context !== 'object') {
    return {};
  }

  return JSON.parse(JSON.stringify(context, (_, value) => {
    if (value instanceof Error) {
      return {
        name: value.name,
        message: value.message,
        stack: value.stack,
      };
    }

    return value;
  }));
}

function writeConsole(level, message, context) {
  const timestamp = new Date().toISOString();
  const formatted = `[${timestamp}] [${level.toUpperCase()}] ${message}`;

  if (!context || Object.keys(context).length === 0) {
    console.log(formatted);
    return;
  }

  console.log(formatted, context);
}

function enqueue(entry) {
  if (!config.coreLogsEnabled) {
    return;
  }

  logQueue.push(entry);

  if (logQueue.length >= config.coreLogsBatchSize || ['error', 'critical', 'alert', 'emergency'].includes(entry.level)) {
    void flush();
    return;
  }

  if (!flushTimer) {
    flushTimer = setTimeout(() => {
      flushTimer = null;
      void flush();
    }, config.coreLogsFlushIntervalMs);
  }
}

async function flush() {
  if (!config.coreLogsEnabled || isFlushing || logQueue.length === 0) {
    return;
  }

  isFlushing = true;

  if (flushTimer) {
    clearTimeout(flushTimer);
    flushTimer = null;
  }

  const entries = logQueue.splice(0, config.coreLogsBatchSize);

  try {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), config.httpTimeoutMs);

    const response = await fetch(new URL(config.coreLogsEndpoint, config.coreApiBaseUrl).toString(), {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${config.coreApiToken}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ entries }),
      signal: controller.signal,
    });

    clearTimeout(timeout);

    if (!response.ok) {
      const body = await response.text();
      throw new Error(`Core log API responded with ${response.status}: ${body}`);
    }
  } catch (error) {
    console.warn(`[${new Date().toISOString()}] [WARN] Failed to push worker logs to core.`, {
      error: error.message,
    });

    logQueue.unshift(...entries);

    await sleep(Math.min(config.retryDelayMs, 3000));
  } finally {
    isFlushing = false;

    if (logQueue.length > 0 && !flushTimer) {
      flushTimer = setTimeout(() => {
        flushTimer = null;
        void flush();
      }, config.coreLogsFlushIntervalMs);
    }
  }
}

function log(level, message, context = {}, event = null) {
  const serializedContext = serializeContext(context);

  writeConsole(level, message, serializedContext);

  enqueue({
    level,
    event,
    message,
    worker_name: config.workerName,
    source: config.workerSource,
    timestamp: new Date().toISOString(),
    context: serializedContext,
  });
}

module.exports = {
  debug(message, context = {}, event = null) {
    log('debug', message, context, event);
  },
  info(message, context = {}, event = null) {
    log('info', message, context, event);
  },
  warn(message, context = {}, event = null) {
    log('warning', message, context, event);
  },
  error(message, context = {}, event = null) {
    log('error', message, context, event);
  },
  flush,
};
