const config = require('./config');

function sleep(ms) {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

async function pushBatch(companies) {
  const endpoint = new URL(config.coreApiEndpoint, config.coreApiBaseUrl).toString();
  const payload = {
    source: config.workerSource,
    worker_name: config.workerName,
    companies,
  };

  let lastError;

  for (let attempt = 1; attempt <= config.retryAttempts; attempt += 1) {
    try {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), config.httpTimeoutMs);

      const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${config.coreApiToken}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload),
        signal: controller.signal,
      });

      clearTimeout(timeout);

      if (!response.ok) {
        const body = await response.text();
        throw new Error(`Core API responded with ${response.status}: ${body}`);
      }

      return response.json();
    } catch (error) {
      lastError = error;

      if (attempt < config.retryAttempts) {
        await sleep(config.retryDelayMs);
      }
    }
  }

  throw lastError;
}

module.exports = {
  pushBatch,
};
