<?php

namespace Clickstorm\CsSeo\Command;

/***************************************************************
 *
 *  Copyright notice
 *
 *  (c) 2016 Marc Hirdes <hirdes@clickstorm.de>, clickstorm GmbH
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use Clickstorm\CsSeo\Domain\Model\Evaluation;
use Clickstorm\CsSeo\Domain\Repository\EvaluationRepository;
use Clickstorm\CsSeo\Service\EvaluationService;
use Clickstorm\CsSeo\Service\FrontendPageService;
use Clickstorm\CsSeo\Utility\ConfigurationUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use \TYPO3\CMS\Extbase\Mvc\Controller\CommandController;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

/**
 * Class EvaluationCommandController
 *
 * @package Clickstorm\CsSeo\Command
 */
class EvaluationCommandController extends CommandController
{

    /**
     * @var \Clickstorm\CsSeo\Domain\Repository\EvaluationRepository
     * @inject
     */
    protected $evaluationRepository;

    /**
     * @var \TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager
     * @inject
     */
    protected $persistenceManager;

    /**
     * @var string
     */
    protected $tableName = 'pages';

    /**
     * @param int $uid
     * @param string $tableName
     */
    public function updateCommand($uid = 0, $tableName = '')
    {
        if (!empty($tableName)) {
            $this->tableName = $tableName;
        }
        $this->processResults($uid);
    }

    /**
     * @param int $uid
     * @param bool $localized
     */
    protected function processResults($uid = 0, $localized = false)
    {
        $items = $this->getAllItems($uid, $localized);
        $this->updateResults($items);

        if (!$localized) {
            $this->processResults($uid, true);
        }
    }

    /**
     * @param int $uid
     * @param bool $localized
     * @return array
     */
    protected function getAllItems($uid, $localized = false)
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($this->tableName);
        $constraints = [];

        $tcaCtrl = $GLOBALS['TCA'][$this->tableName]['ctrl'];
        $allowedDoktypes = ConfigurationUtility::getEvaluationDoktypes();

        // only with doktype page
        if ($this->tableName == 'pages') {
            $constraints[] = $queryBuilder->expr()->in('doktype', $allowedDoktypes);
        }

        // check localization
        if ($localized) {
            if ($tcaCtrl['transForeignTable']) {
                $this->tableName = $tcaCtrl['transForeignTable'];
                $tcaCtrl = $GLOBALS['TCA'][$this->tableName]['ctrl'];
            } else {
                if ($tcaCtrl['languageField']) {
                    $constraints[] = $tcaCtrl['languageField'] . ' > 0';
                } elseif ($this->tableName == 'pages') {
                    $this->tableName = 'pages_language_overlay';
                    $tcaCtrl = $GLOBALS['TCA'][$this->tableName]['ctrl'];
                }
            }
        }

        // if single uid
        if ($uid > 0) {
            if ($localized && $tcaCtrl['transOrigPointerField']) {
                $constraints[] = $queryBuilder->expr()->eq($tcaCtrl['transOrigPointerField'],
                    $queryBuilder->createNamedParameter($uid, \PDO::PARAM_INT));
            } else {
                $constraints[] = $queryBuilder->expr()->eq('uid',
                    $queryBuilder->createNamedParameter($uid, \PDO::PARAM_INT));
            }
        }

        $items = $queryBuilder->select('*')
            ->from($this->tableName)
            ->where(
                $constraints
            )
            ->execute()
            ->fetchAll();

        return $items;
    }

    /**
     * @param $items
     */
    protected function updateResults($items)
    {
        foreach ($items as $item) {
            /** @var FrontendPageService $frontendPageService */
            $frontendPageService = GeneralUtility::makeInstance(FrontendPageService::class, $item, $this->tableName);
            $frontendPage = $frontendPageService->getFrontendPage();

            if (isset($frontendPage['content'])) {
                /** @var EvaluationService $evaluationUtility */
                $evaluationUtility = GeneralUtility::makeInstance(EvaluationService::class);

                $results = $evaluationUtility->evaluate($frontendPage['content'], $this->getFocusKeyword($item));

                $this->saveChanges($results, $item['uid'], $frontendPage['url']);
            }
        }
    }

    /**
     * Get Keyword from record or page
     *
     * @param $record
     * @return string
     */
    protected function getFocusKeyword($record)
    {
        $keyword = '';
        if ($record['tx_csseo']) {
            $metaTableName = 'tx_csseo_domain_model_meta';

            /** @var QueryBuilder $queryBuilder */
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($metaTableName);

            $res = $queryBuilder->select('keyword')
                ->from('tx_csseo_domain_model_evaluation')
                ->where(
                    $queryBuilder->expr()->eq('uid_foreign',
                        $queryBuilder->createNamedParameter($record['uid'], \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($this->tableName))
                )
                ->execute();

            while ($row = $res->fetch()) {
                $keyword = $row['keyword'];
            }
        } else {
            $keyword = $record['tx_csseo_keyword'];
        }

        return $keyword;
    }

    /**
     * store the results in the db
     *
     * @param array $results
     * @param int $uidForeign
     * @param string $url
     */
    protected function saveChanges($results, $uidForeign, $url)
    {
        /**
         * @var Evaluation $evaluation
         */
        $evaluation = $this->evaluationRepository->findByUidForeignAndTableName($uidForeign, $this->tableName);

        if (!$evaluation) {
            $evaluation = GeneralUtility::makeInstance(Evaluation::class);
            $evaluation->setUidForeign($uidForeign);
            $evaluation->setTablenames($this->tableName);
        }

        $evaluation->setUrl($url);
        $evaluation->setResults($results);

        if ($evaluation->_isNew()) {
            $this->evaluationRepository->add($evaluation);
        } else {
            $this->evaluationRepository->update($evaluation);
        }
        $this->persistenceManager->persistAll();
    }

    /**
     * make the ajax update
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @param \Psr\Http\Message\ResponseInterface $response
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function ajaxUpdate(
        \Psr\Http\Message\ServerRequestInterface $request,
        \Psr\Http\Message\ResponseInterface $response
    ) {
        $this->objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $this->evaluationRepository = $this->objectManager->get(EvaluationRepository::class);
        $this->persistenceManager = $this->objectManager->get(PersistenceManager::class);

        // get parameter
        $table = '';
        if (empty($params)) {
            $uid = $GLOBALS['GLOBALS']['HTTP_POST_VARS']['uid'];
            $table = $GLOBALS['GLOBALS']['HTTP_POST_VARS']['table'];
        } else {
            $attr = $params['request']->getParsedBody();
            $uid = $attr['uid'];
            $table = $attr['table'];
        }
        if ($table != '') {
            $this->tableName = $table;
        }
        $this->processResults($uid);

        /** @var $flashMessageService \TYPO3\CMS\Core\Messaging\FlashMessageService */
        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        /** @var $defaultFlashMessageQueue \TYPO3\CMS\Core\Messaging\FlashMessageQueue */
        $flashMessageQueue = $flashMessageService->getMessageQueueByIdentifier('tx_csseo');
        $response->getBody()->write($flashMessageQueue->renderFlashMessages());

        return $response;
    }

    /**
     * @return string
     */
    public function getTableName()
    {
        return $this->tableName;
    }

    /**
     * @param string $tableName
     */
    public function setTableName($tableName)
    {
        $this->tableName = $tableName;
    }

    /**
     * @param $uid
     * @param bool $localizations
     *
     * @return string
     */
    protected function buildQuery($uid, $localizations = false)
    {
        $constraints = ['1'];
        $tcaCtrl = $GLOBALS['TCA'][$this->tableName]['ctrl'];
        $allowedDoktypes = ConfigurationUtility::getEvaluationDoktypes();

        // only with doktype page
        if ($this->tableName == 'pages') {
            $constraints[] = 'doktype IN (' . implode(',', $allowedDoktypes) . ')';
        }

        // check localization
        if ($localizations) {
            if ($tcaCtrl['transForeignTable']) {
                $this->tableName = $tcaCtrl['transForeignTable'];
                $tcaCtrl = $GLOBALS['TCA'][$this->tableName]['ctrl'];
            } else {
                if ($tcaCtrl['languageField']) {
                    $constraints[] = $tcaCtrl['languageField'] . ' > 0';
                } elseif ($this->tableName == 'pages') {
                    $this->tableName = 'pages_language_overlay';
                    $tcaCtrl = $GLOBALS['TCA'][$this->tableName]['ctrl'];
                }
            }
        }

        // if single uid
        if ($uid > 0) {
            if ($localizations && $tcaCtrl['transOrigPointerField']) {
                $constraints[] = $tcaCtrl['transOrigPointerField'] . ' = ' . $uid;
            } else {
                $constraints[] = 'uid = ' . $uid;
            }
        }

        return implode($constraints,
                ' AND ') . BackendUtility::BEenableFields($this->tableName) . BackendUtility::deleteClause($this->tableName);
    }
}
