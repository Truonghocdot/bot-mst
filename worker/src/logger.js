const config = require('./config');

const CORE_LOG_MESSAGE_MAX_LENGTH = 5000;
const CORE_LOG_EVENT_MAX_LENGTH = 255;
const CORE_LOG_SHORT_FIELD_MAX_LENGTH = 100;
const CORE_LOG_CONTEXT_STRING_MAX_LENGTH = 5000;

const logQueue = [];
let flushTimer = null;
let isFlushing = false;

class CoreLogApiError extends Error {
  constructor(status, body) {
    super(`Core log API responded with ${status}: ${body}`);
    this.name = 'CoreLogApiError';
    this.status = status;
  }
}

function formatTimestamp(date = new Date()) {
  return new Intl.DateTimeFormat('sv-SE', {
    timeZone: config.timezoneId || 'Asia/Ho_Chi_Minh',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
  }).format(date).replace(' ', 'T');
}

function sleep(ms) {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

function truncateString(value, maxLength) {
  const stringValue = String(value ?? '');

  if (stringValue.length <= maxLength) {
    return stringValue;
  }

  const truncatedCount = stringValue.length - maxLength;
  const suffix = `... [truncated ${truncatedCount} chars]`;
  const sliceLength = Math.max(0, maxLength - suffix.length);

  return `${stringValue.slice(0, sliceLength)}${suffix}`;
}

function serializeError(error) {
  return {
    name: truncateString(error.name || 'Error', CORE_LOG_SHORT_FIELD_MAX_LENGTH),
    message: truncateString(error.message || '', CORE_LOG_MESSAGE_MAX_LENGTH),
    stack: error.stack
      ? truncateString(error.stack, CORE_LOG_CONTEXT_STRING_MAX_LENGTH)
      : null,
  };
}

function serializeContext(context) {
  if (!context || typeof context !== 'object') {
    return {};
  }

  const seen = new WeakSet();

  try {
    return JSON.parse(JSON.stringify(context, (_, value) => {
      if (value instanceof Error) {
        return serializeError(value);
      }

      if (typeof value === 'string') {
        return truncateString(value, CORE_LOG_CONTEXT_STRING_MAX_LENGTH);
      }

      if (typeof value === 'bigint') {
        return value.toString();
      }

      if (typeof value === 'function') {
        return `[Function ${value.name || 'anonymous'}]`;
      }

      if (value && typeof value === 'object') {
        if (seen.has(value)) {
          return '[Circular]';
        }

        seen.add(value);
      }

      return value;
    }));
  } catch (error) {
    return {
      serialization_error: error instanceof Error
        ? truncateString(error.message, CORE_LOG_CONTEXT_STRING_MAX_LENGTH)
        : 'Unknown serialization error.',
    };
  }
}

function normalizeEntry(entry) {
  return {
    level: entry.level,
    event: entry.event
      ? truncateString(entry.event, CORE_LOG_EVENT_MAX_LENGTH)
      : null,
    message: truncateString(entry.message, CORE_LOG_MESSAGE_MAX_LENGTH),
    worker_name: entry.worker_name
      ? truncateString(entry.worker_name, CORE_LOG_SHORT_FIELD_MAX_LENGTH)
      : null,
    source: entry.source
      ? truncateString(entry.source, CORE_LOG_SHORT_FIELD_MAX_LENGTH)
      : null,
    timestamp: entry.timestamp
      ? truncateString(entry.timestamp, CORE_LOG_SHORT_FIELD_MAX_LENGTH)
      : null,
    context: serializeContext(entry.context),
  };
}

function shouldRetryFlush(error) {
  if (typeof error?.status !== 'number') {
    return true;
  }

  if (error.status === 429) {
    return true;
  }

  return error.status >= 500;
}

function writeConsole(level, message, context) {
  const timestamp = formatTimestamp();
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
      const body = truncateString(await response.text(), CORE_LOG_CONTEXT_STRING_MAX_LENGTH);
      throw new CoreLogApiError(response.status, body);
    }
  } catch (error) {
    const retrying = shouldRetryFlush(error);

    console.warn(`[${formatTimestamp()}] [WARN] Failed to push worker logs to core.`, {
      error: error.message,
      retrying,
      dropped_entries: retrying ? 0 : entries.length,
    });

    if (retrying) {
      logQueue.unshift(...entries);

      await sleep(Math.min(config.retryDelayMs, 3000));
    }
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
  const entry = normalizeEntry({
    level,
    event,
    message,
    worker_name: config.workerName,
    source: config.workerSource,
    timestamp: formatTimestamp(),
    context,
  });

  writeConsole(level, entry.message, entry.context);
  enqueue(entry);
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
