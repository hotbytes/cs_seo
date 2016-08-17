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
use Clickstorm\CsSeo\Utility\EvaluationUtility;
use Clickstorm\CsSeo\Utility\FrontendPageUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use \TYPO3\CMS\Extbase\Mvc\Controller\CommandController;

class EvaluationCommandController extends CommandController {

	/**
	 * @var \Clickstorm\CsSeo\Domain\Repository\EvaluationRepository
	 * @inject
	 */
	protected $evaluationRepository;

	/**
	 * @param int $uidForeign
	 * @param string $tableName
	 */
	public function updateCommand($uidForeign = 0, $tableName = 'pages') {
		$items = $this->getAllItems($uidForeign, $tableName);

		foreach ($items as $item) {
			/** @var FrontendPageUtility $frontendPageUtility */
			$frontendPageUtility = GeneralUtility::makeInstance(FrontendPageUtility::class, $item);
			$html = $frontendPageUtility->getHTML();

			if(!empty($html)) {
				/** @var EvaluationUtility $evaluationUtility */
				$evaluationUtility = GeneralUtility::makeInstance(EvaluationUtility::class, $html, $item['tx_csseo_keyword']);
				$results = $evaluationUtility->evaluate();

				$this->saveChanges($results, $item['uid'], $tableName);
			}
		}

	}

	/**
	 * store the results in the db
	 * @param $results
	 * @param $uidForeign
	 * @param $tableName
	 */
	protected function saveChanges($results, $uidForeign, $tableName) {
		/**
		 * @var Evaluation $evaluation
		 */
		$evaluation = $this->evaluationRepository->findByUidForeignAndTableName($uidForeign, $tableName);

		if(!$evaluation) {
			$evaluation = GeneralUtility::makeInstance(Evaluation::class);
			$evaluation->setUidForeign($uidForeign);
			$evaluation->setTablenames($tableName);
		}

		$evaluation->setResults($results);

		if($evaluation->_isNew()) {
			$this->evaluationRepository->add($evaluation);
		} else {
			$this->evaluationRepository->update($evaluation);
		}
	}

	protected function getAllItems($uidForeign, $tableName) {
		$items = [];

		$where = '1';

		if($tableName == 'pages') {
			$where .= ' AND doktype=1';
		}

		if($uidForeign > 0) {
			$where .= ' AND uid = ' . $uidForeign;
		}

		$where .=  BackendUtility::BEenableFields($tableName);

		$res = $this->getDatabaseConnection()->exec_SELECTquery(
			'*',
			$tableName,
			$where
		);
		while ($row = $this->getDatabaseConnection()->sql_fetch_assoc($res)) {
			$items[] = $row;
		}
		return $items;
	}

	/**
	 * Returns the database connection
	 *
	 * @return \TYPO3\CMS\Core\Database\DatabaseConnection
	 */
	protected function getDatabaseConnection()
	{
		return $GLOBALS['TYPO3_DB'];
	}


}