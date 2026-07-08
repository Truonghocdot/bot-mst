const config = require('./config');
const { pushBatch } = require('./core-api-client');
const { scrapeMasothueBatch } = require('./masothue-scraper');

function sleep(ms) {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

function log(message, payload) {
  const timestamp = new Date().toISOString();

  if (payload === undefined) {
    console.log(`[${timestamp}] ${message}`);
    return;
  }

  console.log(`[${timestamp}] ${message}`, payload);
}

async function runOnce() {
  const companies = await scrapeMasothueBatch();

  if (companies.length === 0) {
    log('No companies found on the listing page.');
    return;
  }

  log(`Scraped ${companies.length} companies from ${config.targetUrl}.`);

  const response = await pushBatch(companies);

  log('Core API accepted batch.', response);
}

async function runWatch() {
  while (true) {
    try {
      await runOnce();
    } catch (error) {
      log(`Worker cycle failed: ${error.message}`);
    }

    await sleep(config.pollIntervalMs);
  }
}

async function main() {
  if (process.argv.includes('--watch')) {
    await runWatch();
    return;
  }

  await runOnce();
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
