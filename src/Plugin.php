<?php

/**
 * Craft Redirect plugin
 *
 * @author    Venveo
 * @copyright Copyright (c) 2017 dolphiq
 * @copyright Copyright (c) 2019 Venveo
 */

namespace venveo\redirect;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\errors\MigrationException;
use craft\events\ExceptionEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\ErrorHandler;
use craft\web\UrlManager;
use venveo\redirect\elements\FeedMeRedirect;
use venveo\redirect\models\Settings;
use venveo\redirect\services\CatchAll;
use venveo\redirect\services\Redirects;
use yii\base\Event;


/**
 * @property mixed $settingsResponse
 * @property Redirects $redirects
 * @property array $cpNavItem
 * @property CatchAll $catchAll
 * @property mixed _redirectsService
 * @property mixed _catchAllService
 */
class Plugin extends BasePlugin
{
    /** @var self $plugin */
    public static $plugin;

    public $schemaVersion = '1.1.1';

    protected $_redirectsService;
    protected $_catchAllService;

    const PERMISSION_MANAGE_REDIRECTS = 'vredirect:redirects:manage';
    const PERMISSION_MANAGE_404S = 'vredirect:404s:manage';

    /**
     * Returns the Redirects service.
     *
     * @return Redirects The Redirects service
     */
    public function getRedirects(): Redirects
    {
        if ($this->_redirectsService == null) {
            $this->_redirectsService = new Redirects();
        }
        return $this->_redirectsService;
    }

    public function getCatchAll()
    {
        if ($this->_catchAllService == null) {
            $this->_catchAllService = new CatchAll();
        }

        return $this->_catchAllService;
    }

    public $hasCpSection = true;
    public $hasCpSettings = true;

    public function install()
    {
        if ($this->beforeInstall() === false) {
            return false;
        }

        $migrator = $this->getMigrator();

        $oldPlugin = Craft::$app->plugins->getPlugin('redirect');
        if ($oldPlugin) {
            // We need to copy the migrations that have already been run on the original plugin to our new plugin
            $oldPluginMigrations = $oldPlugin->getMigrator()->getMigrationHistory();
            foreach ($oldPluginMigrations as $name) {
                $migrator->addMigrationHistory($name);
            }
            // Now we'll apply all the new migrations
            $migrator->up();

            // Disable the old plugin
            Craft::$app->plugins->disablePlugin('redirect');

            $this->isInstalled = true;
            $this->afterInstall();
            return null;
        }

        // Run the install migration, if there is one
        if (($migration = $this->createInstallMigration()) !== null) {
            try {
                $migrator->migrateUp($migration);
            } catch (MigrationException $e) {
                return false;
            }
        }

        // Mark all existing migrations as applied
        foreach ($migrator->getNewMigrations() as $name) {
            $migrator->addMigrationHistory($name);
        }

        $this->isInstalled = true;

        $this->afterInstall();

        return null;
    }

    /*
    *
    *  The Craft plugin documentation points to the EVENT_REGISTER_CP_NAV_ITEMS event to register navigation items.
    *  The getCpNavItem was found in the source and will check the user privilages already.
    *
    */
    public function getCpNavItem()
    {
        $subnavItems = [];
        $currentUser = Craft::$app->getUser()->getIdentity();
        if ($currentUser->can('vredirect:redirects:manage')) {
            $subnavItems['redirects'] = [
                'label' => Craft::t('vredirect', 'Redirects'),
                'url' => 'redirect/redirects'
            ];
        }

        if ($currentUser->can('vredirect:404s:manage')) {
            $subnavItems['catch-all'] = [
                'label' => Craft::t('vredirect', 'Registered 404s'),
                'url' => 'redirect/catch-all'
            ];
        }

        return [
            'url' => 'redirect',
            'label' => Craft::t('vredirect', 'Site Redirects'),
            'fontIcon' => 'share',
            'subnav' => $subnavItems
        ];
    }


    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): string
    {
        return Craft::$app->view->renderTemplate(
            'vredirect/settings',
            [
                'settings' => $this->getSettings()
            ]
        );
    }

    private function registerCpRoutes()
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function (RegisterUrlRulesEvent $event) {
            $event->rules = array_merge($event->rules, [
                'redirect' => ['template' => 'vredirect/index'],

                'redirect/catch-all' => 'vredirect/catch-all/index',

                'redirect/dashboard' => 'vredirect/dashboard/index',

                'redirect/redirects' => 'vredirect/redirects/index',
                'redirect/redirects/new' => 'vredirect/redirects/edit-redirect',
                'redirect/redirects/<redirectId:\d+>' => 'vredirect/redirects/edit-redirect',
            ]);
        });
    }

    /**
     * Registers our custom feed import logic if feed-me is enabled. Also note, we're checking for craft\feedme
     */
    private function registerFeedMeElement()
    {
        if (Craft::$app->plugins->isPluginEnabled('feed-me') && class_exists(\craft\feedme\Plugin::class)) {
            Event::on(\craft\feedme\services\Elements::class, \craft\feedme\services\Elements::EVENT_REGISTER_FEED_ME_ELEMENTS, function (\craft\feedme\events\RegisterFeedMeElementsEvent $e) {
                $e->elements[] = FeedMeRedirect::class;
            });
        }
    }

    private function registerPermissions()
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function (RegisterUserPermissionsEvent $event) {
            $event->permissions[\Craft::t('vredirect', 'Redirects')] = [
                'vredirect:redirects:manage' => [
                    'label' => \Craft::t('vredirect', 'Manage Redirects on Editable Sites'),
                ],
                'vredirect:404s:manage' => [
                    'label' => \Craft::t('vredirect', 'Manage Registered 404s')
                ]
            ];
        });
    }


    public function init()
    {
        parent::init();
        self::$plugin = $this;
        $settings = self::$plugin->getSettings();

        $this->registerCpRoutes();
        $this->registerFeedMeElement();
        $this->registerPermissions();

        // Remove our soft-deleted redirects when Craft is ready
        Event::on(Gc::class, Gc::EVENT_RUN, function () {
            Craft::$app->gc->hardDelete('{{%venveo_redirects}}');
        });

        if (!$settings->redirectsActive) {
            // Return early.
            return;
        }

        // Start lookin' for some 404s!
        Event::on(
            ErrorHandler::class,
            ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION,
            static function (ExceptionEvent $event) {
                $request = Craft::$app->request;
                // We don't care about requests that aren't on our site frontend
                if (!$request->getIsSiteRequest() || $request->getIsLivePreview()) {
                    return;
                }
                $exception = $event->exception;

                if ($exception instanceof \Twig\Error\RuntimeError &&
                    ($previousException = $exception->getPrevious()) !== null) {
                    $exception = $previousException;
                }

                if ($exception instanceof \yii\web\HttpException && $exception->statusCode === 404) {
                    self::$plugin->redirects->handle404($exception);
                }
            }
        );
    }
}
