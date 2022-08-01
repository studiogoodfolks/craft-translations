<?php

/**
 * Translations for Craft plugin for Craft CMS 3.x
 *
 * Translations for Craft eliminates error prone and costly copy/paste workflows
 * for launching human translated Craft CMS web content.
 *
 * @link      http://www.acclaro.com/
 * @copyright Copyright (c) 2018 Acclaro
 */

namespace acclaro\translations\controllers;

use Craft;
use craft\web\Controller;
use acclaro\translations\Constants;
use acclaro\translations\Translations;

class CategoryController extends Controller
{
    /**
     * Edit Global Set Drafts
     *
     * @param array $variables
     * @return void
     */
    public function actionEditDraft(array $variables = array())
    {
        $variables = Craft::$app->getRequest()->resolve()[1];

        /** @var craft\elements\User $currentUser */
        $currentUser = Craft::$app->getUser()->getIdentity();

        $variables['canEdit'] = $currentUser->can('translations:orders:create');

        if (empty($variables['slug'])) {
            Craft::$app->getSession()->setError(Translations::$plugin->translator->translate('app', 'Param “{name}” doesn’t exist.', array('name' => 'categoryGroup')));
            return;
        }

        $category = explode('-', $variables['slug']);
        $categoryId = $category[0];
        $category = Craft::$app->categories->getCategoryById($categoryId);
        $categoryGroup = Craft::$app->categories->getGroupById($category->groupId);
        $variables['category'] = $category;
        $variables['groupHandle'] = $variables['group'];
        $variables['group'] = $categoryGroup;

        $variables['categoryId'] = $categoryId;

        $variables['orders'] = array();

        $draft = Translations::$plugin->categoryDraftRepository->getDraftById($variables['draftId']);

        $variables['element'] = $draft;

        $variables['file'] = Translations::$plugin->fileRepository->getFileByDraftId($draft->draftId, $categoryId);

        $variables['continueEditingUrl'] = '';
        $variables['nextCategoryUrl'] = '';
        $variables['title'] = $category->title;
        $variables['siteIds'] = Craft::$app->getSites()->getAllSiteIds();
        $variables['showPreviewBtn'] = false;

        $this->renderTemplate('translations/categories/_editDraft', $variables);
    }

    /**
     * Save Global Set Drafts
     *
     * @param array $variables
     * @return void
     */
    public function actionSaveDraft()
    {
        $this->requirePostRequest();

        $site = $this->request->getParam('site', Craft::$app->sites->getPrimarySite()->id);

        $categoryId = $this->request->getParam('categoryId');

        $category = Translations::$plugin->categoryRepository->getCategoryById($categoryId, $site);

        if (!$category) {
            Craft::$app->getSession()->setError(Translations::$plugin->translator->translate('app', 'No category exists with the ID “{id}”.', array('id' => $categoryId)));
            return;
        }

        $draftId = $this->request->getParam('draftId');

        if ($draftId) {
            $draft = Translations::$plugin->categoryDraftRepository->getDraftById($draftId);

            if (!$draft) {
                Craft::$app->getSession()->setError(Translations::$plugin->translator->translate('app', 'No draft exists with the ID “{id}”.', array('id' => $draftId)));
                return;
            }

            $draft->title = $this->request->getParam('title') ?? $draft->title;
            $draft->slug = $this->request->getParam('slug') ?? $draft->slug;
        } else {
            $draft = Translations::$plugin->categoryDraftRepository->makeNewDraft();
            $draft->id = $category->id;
            $draft->site = $site;
        }

        $fields = $this->request->getParam('fields') ?? [];

        if ($fields) {
            $draft->setFieldValues($fields);
        }

        if (Translations::$plugin->categoryDraftRepository->saveDraft($draft, $fields)) {
            Craft::$app->getSession()->setNotice(Translations::$plugin->translator->translate('app', 'Draft saved.'));

            $this->redirect($draft->getCpEditUrl(), 302, true);
        } else {
            Craft::$app->getSession()->setError(Translations::$plugin->translator->translate('app', 'Couldn’t save draft.'));

            Craft::$app->urlManager->setRouteParams(array(
                'category' => $draft
            ));
        }
    }

    /**
     * Publish Global Set Drafts
     *
     * @param array $variables
     * @return void
     */
    public function actionPublishDraft()
    {
        $this->requirePostRequest();

        $categoryId = Craft::$app->getRequest()->getParam('categoryId');
        $siteId = Craft::$app->getRequest()->getParam('site');
        $category = Translations::$plugin->categoryRepository->getCategoryById($categoryId, $siteId);

        if (!$category) {
            Craft::$app->getSession()->setError(Translations::$plugin->translator->translate('app', 'No category exists with the ID “{id}”.', array('id' => $categoryId)));
            return;
        }

        $draftId = Craft::$app->getRequest()->getParam('draftId');
        if ($draftId) {
            $draft = Translations::$plugin->categoryDraftRepository->getDraftById($draftId);

            if (!$draft) {
                Craft::$app->getSession()->setError(Translations::$plugin->translator->translate('app', 'No draft exists with the ID “{id}”.', array('id' => $draftId)));
                return;
            }
        } else {
            $draft = Translations::$plugin->categoryDraftRepository->makeNewDraft();
        }

        $fields = $this->request->getParam('fields') ?? [];

        if ($fields) {
            $draft->setFieldValues($fields);
        }

        $draft->id = $category->id;
        $draft->categoryId = $category->id;
        // restore the original name
        $draft->name = $category->title;
        $draft->title = $this->request->getParam('title') ?? $category->title;
        $draft->site = $siteId;

        $file = Translations::$plugin->fileRepository->getFileByDraftId($draftId, $category->id);

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            if ($file) {
                $order = Translations::$plugin->orderRepository->getOrderById($file->orderId);

                $file->status = Constants::FILE_STATUS_PUBLISHED;
                $file->draftId = 0;

                Translations::$plugin->fileRepository->saveFile($file);

                $order->status = Translations::$plugin->orderRepository->getNewStatus($order);
                Translations::$plugin->orderRepository->saveOrder($order);
            }

            if (Translations::$plugin->categoryDraftRepository->publishDraft($draft)) {
                $this->redirect($category->getCpEditUrl(), 302, true);

                Craft::$app->getSession()->setNotice(Translations::$plugin->translator->translate('app', 'Draft published.'));
                $transaction->commit();

                return Translations::$plugin->categoryDraftRepository->deleteDraft($draft);
            } else {
                Craft::$app->getSession()->setError(Translations::$plugin->translator->translate('app', 'Couldn’t publish draft.'));
                $transaction->rollBack();

                // Send the draft back to the template
                Craft::$app->urlManager->setRouteParams(array(
                    'draft' => $draft
                ));
            }
        } catch (\Exception $e) {
            $transaction->rollBack();
        }
    }

    /**
     * Delete Global Set Drafts
     *
     * @param array $variables
     * @return void
     */
    public function actionDeleteDraft()
    {
        $this->requirePostRequest();

        $draftId = Craft::$app->getRequest()->getParam('draftId');
        $draft = Translations::$plugin->categoryDraftRepository->getDraftById($draftId);

        if (!$draft) {
            Craft::$app->getSession()->setError(Translations::$plugin->translator->translate('app', 'No draft exists with the ID “{id}”.', array('id' => $draftId)));
            return;
        }

        $category = Translations::$plugin->categoryRepository->getCategoryById($draft->categoryId);
        $url = $category->getCpEditUrl();
        $elementId = $draft->categoryId;

        Translations::$plugin->categoryDraftRepository->deleteDraft($draft);

        Translations::$plugin->fileRepository->deleteByDraftId($draftId, $elementId);

        Craft::$app->getSession()->setError(Translations::$plugin->translator->translate('app', 'Draft deleted.'));

        return $this->redirect($url, 302, true);
    }
}