const config = require('./config');

async function fetchRotatingProxyConfig() {
  const endpoint = new URL(config.coreProxyEndpoint, config.coreApiBaseUrl).toString();
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), config.httpTimeoutMs);

  try {
    const response = await fetch(endpoint, {
      method: 'GET',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${config.coreApiToken}`,
      },
      signal: controller.signal,
    });

    clearTimeout(timeout);

    if (!response.ok) {
      const body = await response.text();

      throw new Error(`Core proxy API responded with ${response.status}: ${body}`);
    }

    const payload = await response.json();

    if (!payload.ok || !payload.enabled || !payload.proxy?.server) {
      return null;
    }

    return {
      source: payload.proxy.provider || 'core',
      server: payload.proxy.server,
      username: payload.proxy.username || null,
      password: payload.proxy.password || null,
      location: payload.proxy.location || null,
      network: payload.proxy.network || null,
      expiresInSeconds: payload.proxy.expires_in_seconds || null,
    };
  } finally {
    clearTimeout(timeout);
  }
}

module.exports = {
  fetchRotatingProxyConfig,
};
