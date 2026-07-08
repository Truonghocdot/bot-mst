const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const { readDetail, readListingItems } = require('./masothue-scraper');

async function main() {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  const detailPage = await browser.newPage();

  const listHtml = fs.readFileSync(path.resolve(__dirname, '../../docs/new.html'), 'utf8');
  const detailHtml = fs.readFileSync(path.resolve(__dirname, '../../docs/detail.html'), 'utf8');

  await page.setContent(listHtml, { waitUntil: 'domcontentloaded' });
  await detailPage.setContent(detailHtml, { waitUntil: 'domcontentloaded' });

  const listings = await readListingItems(page);
  const detail = await readDetail(detailPage);

  console.log(JSON.stringify({
    listing_count: listings.length,
    first_listing: listings[0],
    detail,
  }, null, 2));

  await browser.close();
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
