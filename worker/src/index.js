const config = require('./config');
const { pushBatch } = require('./core-api-client');
const logger = require('./logger');
const { scrapeMasothueBatch } = require('./masothue-scraper');

function sleep(ms) {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

async function runOnce() {
  const companies = await scrapeMasothueBatch();

  if (companies.length === 0) {
    logger.warn('No companies found on the listing page.', {}, 'worker.empty');
    return;
  }

  logger.info(`Scraped ${companies.length} companies from ${config.targetUrl}.`, {
    count: companies.length,
    target_url: config.targetUrl,
  }, 'worker.scraped');

  const response = await pushBatch(companies);

  logger.info('Core API accepted batch.', response, 'worker.batch_accepted');
}

async function runWatch() {
  while (true) {
    try {
      await runOnce();
    } catch (error) {
      logger.error(`Worker cycle failed: ${error.message}`, {
        stack: error.stack,
      }, 'worker.cycle_failed');
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
  logger.error(`Worker fatal error: ${error.message}`, {
    stack: error.stack,
  }, 'worker.fatal');
  logger.flush().finally(() => {
    process.exitCode = 1;
  });
});
