cd /var/www/html/bot-mst
git pull

cd /var/www/html/bot-mst/core
composer dump-autoload -o
sudo -u www-data php artisan optimize:clear

sudo /var/www/html/bot-mst/deploy/fix-permissions.sh /var/www/html/bot-mst

sudo supervisorctl restart bot-mst-queue
sudo supervisorctl restart bot-mst-worker