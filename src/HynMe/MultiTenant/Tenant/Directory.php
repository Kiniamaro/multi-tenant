<?php namespace HynMe\MultiTenant\Tenant;

use Config, File;
use HynMe\MultiTenant\Contracts\DirectoryContract;
use HynMe\MultiTenant\Models\Website;
use Illuminate\Support\ClassLoader;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;

/**
 * Class Directory
 *
 * Helps with tenant directories
 * - cache
 * - views
 * - migrations
 * - media
 * - vendor
 * - lang
 *
 * @package HynMe\MultiTenant\Tenant
 */
class Directory implements DirectoryContract
{
    /**
     * @var Website
     */
    protected $website;

    /**
     * Base tenant path
     * @var string
     */
    protected $base_path;

    /**
     * Old directory for base
     * @var string|null
     */
    protected $old_path;

    public function __construct(Website $website)
    {
        $this->website = $website;

        if($this->website->isDirty('identifier')) {
            $this->old_path = sprintf("%s/%d-%s/",
                Config::get('multi-tenant.tenant-directory') ? Config::get('multi-tenant.tenant-directory') : storage_path('multi-tenant'),
                $this->website->id,
                $this->website->getOriginal('identifier'));
            if(!File::isDirectory($this->old_path))
                $this->old_path = null;
        }

        $this->base_path = sprintf("%s/%d-%s/",
            Config::get('multi-tenant.tenant-directory') ? Config::get('multi-tenant.tenant-directory') : storage_path('multi-tenant'),
            $this->website->id,
            $this->website->identifier);
    }


    /**
     * Tenant config directory
     *
     * @return string|null
     */
    public function config()
    {
        return $this->base() ? sprintf("%sconfig/", $this->base()) : null;
    }
    /**
     * Tenant views directory
     *
     * @return string|null
     */
    public function views()
    {
        return $this->base() ? sprintf("%sviews/", $this->base()) : null;
    }

    /**
     * Tenant language/trans directory
     *
     * @return string|null
     */
    public function lang()
    {
        return $this->base() ? sprintf("%slang/", $this->base()) : null;
    }

    /**
     * Tenant vendor directory
     *
     * @return string|null
     */
    public function vendor()
    {
        return $this->base() ? sprintf("%svendor/", $this->base()) : null;
    }

    /**
     * Tenant cache directory
     *
     * @return string|null
     */
    public function cache()
    {
        return $this->base() ? sprintf("%scache/", $this->base()) : null;
    }

    /**
     * Tenant media directory
     *
     * @return string|null
     */
    public function media()
    {
        return $this->base() ? sprintf("%smedia/", $this->base()) : null;
    }

    /**
     * Tenant base path
     *
     * @return string|null
     */
    public function base()
    {
        return $this->base_path;
    }

    /**
     * Old base path for tenant
     * @return null|string
     */
    public function old_base()
    {
        return $this->old_path;
    }

    /**
     * Register all available paths into the laravel system
     *
     * @param \Illuminate\Contracts\Foundation\Application $app
     * @return Directory
     */
    public function registerPaths($app)
    {
        // only register if tenant directory exists
        if($this->base())
        {
            // adds views in base namespace
            if($this->views())
                $app['view']->addLocation($this->views());
            // merges overruling config files
            if($this->config()) {
                foreach (File::allFiles($this->config()) as $path) {
                    $key = File::name($path);
                    $app['config']->set($key, array_merge(require $path, $app['config']->get($key, [])));
                }
            }
            // add additional vendor directory
            if($this->vendor())
                ClassLoader::addDirectories([$this->vendor()]);

            // set cache
            $app['config']->set('cache.prefix', "{$app['config']->get('cache.prefix')}-{$this->website->id}");
            // @TODO we really can't use cache yet for application cache

            // replaces lang directory
            if($this->lang()) {
                $path = $this->lang();

                $app->bindShared('translation.loader', function($app) use ($path)
                {
                    return new FileLoader($app['files'], $path);
                });
                $app->bindShared('translator', function($app)
                {
                    $translator = new Translator($app['translation.loader'], $app['config']['app.locale']);
                    $translator->setFallback($app['config']['app.fallback_locale']);
                    return $translator;
                });
            }
            // identify a possible routes.php file
            if($this->routes())
                File::requireOnce($this->routes());
        }
        return $this;
    }

    /**
     * Creates tenant directories
     *
     * Creates all required tenant directories
     * @return boolean
     */
    public function create()
    {
        $done = 0;
        foreach(['base', 'views', 'lang', 'cache', 'media', 'vendor'] as $i => $directory)
        {
            if(File::makeDirectory($this->{$directory}(), 0755, true))
                $done++;
        }
        return $done == ($i+1);
    }


    /**
     * Path to tenant routes.php
     *
     * @return string|null
     */
    public function routes()
    {
        if($this->base())
            $routes = sprintf("%sroutes.php", $this->base());

        return $this->base() && File::exists($routes) ? $routes : null;
    }
}