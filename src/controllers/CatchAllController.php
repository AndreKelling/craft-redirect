<?php

/**
 * @author    Venveo
 * @copyright Copyright (c) 2019 Venveo
 * @link      https://www.venveo.com
 */

namespace venveo\redirect\controllers;

use Craft;
use craft\db\Paginator;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\Response;
use venveo\redirect\Plugin;
use venveo\redirect\records\CatchAllUrl;
use yii\db\Query;

class CatchAllController extends Controller
{

    // Public Methods
    // =========================================================================

    /**
     * Called before displaying the redirect settings index page.
     *
     * @return Response
     * @throws \craft\errors\SiteNotFoundException
     */
    public function actionIndex()
    {
        $currentUser = Craft::$app->getUser()->getIdentity();
        if (!$currentUser->can(Plugin::PERMISSION_MANAGE_404S)) {
            return Craft::$app->response->setStatusCode('403', Craft::t('vredirect', 'You lack the required permissions to manage registered 404s'));
        }

        return $this->renderTemplate('vredirect/_catch-all/index', [
            'catchAllQuery' => CatchAllUrl::find()->orderBy('hitCount DESC')
        ]);
    }

    /**
     * @return \yii\web\Response
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionGetFiltered()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $currentUser = Craft::$app->getUser()->getIdentity();
        if (!$currentUser->can(Plugin::PERMISSION_MANAGE_404S)) {
            return Craft::$app->response->setStatusCode('403', Craft::t('vredirect', 'You lack the required permissions to manage registered 404s'));
        }

        $data = \GuzzleHttp\json_decode(Craft::$app->request->getRawBody(), true);
        $recordQuery = CatchAllUrl::find();

        // Handle sorting...
        if (isset($data['sort']['field'], $data['sort']['type'])) {
            $cols = [];
            $cols[$data['sort']['field']] = $data['sort']['type'] == 'asc' ? SORT_ASC : SORT_DESC;
            $recordQuery->addOrderBy($cols);
        }

        // Handle searching
        if (isset($data['searchTerm']) && $data['searchTerm'] != '') {
            $recordQuery->andFilterWhere(['like', 'uri', $data['searchTerm']]);
        }

        // Handle filters
        if (isset($data['columnFilters']) && !empty($data['columnFilters'])) {
            foreach ($data['columnFilters'] as $filter => $value) {
                if ($value == '') {
                    continue;
                }
                if ($value === 'true' || $value === true) {
                    $value = true;
                } else {
                    $value = false;
                }
                $recordQuery->andWhere([$filter => $value]);
            }
        } else {
            $recordQuery->andWhere(['ignored' => false]);
        }
        $data['page'] = $data['page'] ?? 1;
        $recordQuery->limit = $data['perPage'] ?? 10;

        /** @var Query $query */
        $paginator = new Paginator((clone $recordQuery)->limit(null), [
            'currentPage' => $data['page'],
            'pageSize' => $data['perPage'] ?: 100,
        ]);

        // Process the results
        $rows = [];
        $sites = [];

        foreach ($paginator->getPageResults() as $record) {
            if (!isset($sites[$record->siteId])) {
                $sites[$record->siteId] = Craft::$app->sites->getSiteById($record->siteId)->name;
            }
            $siteName = $sites[$record->siteId];
            $row = $record->toArray();
            $row['siteName'] = $siteName;
            $row['createUrl'] = UrlHelper::cpUrl('redirect/redirects/new', ['from' => $record->id]);
            $rows[] = $row;
        }
        return $this->asJson(['totalRecords' => $paginator->totalResults, 'rows' => $rows, 'page' => $paginator->currentPage]);
    }

    public function actionDelete()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $currentUser = Craft::$app->getUser()->getIdentity();
        if (!$currentUser->can(Plugin::PERMISSION_MANAGE_404S)) {
            return Craft::$app->response->setStatusCode('403', Craft::t('vredirect', 'You lack the required permissions to manage registered 404s'));
        }

        $data = \GuzzleHttp\json_decode(Craft::$app->request->getRawBody(), true);
        CatchAllUrl::deleteAll(['in', 'id', $data]);
        return $this->asJson('Deleted');
    }

    public function actionIgnore()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $currentUser = Craft::$app->getUser()->getIdentity();
        if (!$currentUser->can(Plugin::PERMISSION_MANAGE_404S)) {
            return Craft::$app->response->setStatusCode('403', Craft::t('vredirect', 'You lack the required permissions to manage registered 404s'));
        }

        $data = \GuzzleHttp\json_decode(Craft::$app->request->getRawBody(), true);
        CatchAllUrl::updateAll(['ignored' => true], ['in', 'id', $data]);
        return $this->asJson('Ignored');
    }

    public function actionUnIgnore()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $currentUser = Craft::$app->getUser()->getIdentity();
        if (!$currentUser->can(Plugin::PERMISSION_MANAGE_404S)) {
            return Craft::$app->response->setStatusCode('403', Craft::t('vredirect', 'You lack the required permissions to manage registered 404s'));
        }


        $data = \GuzzleHttp\json_decode(Craft::$app->request->getRawBody(), true);
        CatchAllUrl::updateAll(['ignored' => false], ['in', 'id', $data]);
        return $this->asJson('Un-ignored');
    }
}
