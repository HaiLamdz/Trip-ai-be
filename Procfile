web: php artisan serve --host=0.0.0.0 --port=$PORT
worker: php artisan queue:work redis --sleep=3 --tries=3 --timeout=90 --max-jobs=1000
