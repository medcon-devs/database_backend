<?php

namespace App\Providers;

use App\Models\CSVData;
use App\Observers\CSVDataSearchObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Replace Scout's synchronous observer with a resilient observer so a
        // Typesense outage cannot block writes to the primary MariaDB database.
        CSVData::disableSearchSyncing();
        CSVData::observe(CSVDataSearchObserver::class);
    }
}
