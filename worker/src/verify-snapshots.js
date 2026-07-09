const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const { readListingItems } = require('./masothue-scraper');

async function main() {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();

  const listHtml = fs.readFileSync(path.resolve(__dirname, '../../docs/new.html'), 'utf8');

  await page.setContent(listHtml, { waitUntil: 'domcontentloaded' });

  const listings = await readListingItems(page);

  console.log(JSON.stringify({
    listing_count: listings.length,
    first_listing: listings[0],
  }, null, 2));

  await browser.close();
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
