<?php

namespace Vierwd\VierwdBase\Hooks;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2015 Robert Vock <robert.vock@4wdmedia.de>, FORWARD MEDIA
 *
 *  All rights reserved
 *
 ***************************************************************/

use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

class ContentElements implements SingletonInterface {

	public static $oldProcFunc;

	protected static $groups = ['vierwd' => []];
	protected static $groupNames = ['vierwd' => 'FORWARD MEDIA'];

	protected static $fceConfiguration = [];

	/**
	 * process the CType and sort custom FCEs into a special group
	 */
	public function processCType($params, $refObj) {
		if (static::$oldProcFunc) {
			GeneralUtility::callUserFunction(static::$oldProcFunc, $params, $refObj);
		}

		$CTypes = [];
		foreach (self::$groups as $groupKey => $groupCTypes) {
			foreach ($groupCTypes as $CType) {
				$CTypes[$CType] = $groupKey;
			}
		}

		$groups = array_combine(array_keys(self::$groups), array_fill(0, count(self::$groups), []));

		$params['items'] = array_filter($params['items'], function($element) use (&$groups, $CTypes) {
			$CType = $element[1];

			if (isset($CTypes[$CType])) {
				$groupKey = $CTypes[$CType];
				$groups[$groupKey][] = $element;
				return false;
			}

			return true;
		});

		foreach ($groups as $groupKey => $elements) {
			$params['items'][] = [self::$groupNames[$groupKey], '--div--'];

			usort($elements, function($plugin1, $plugin2) {
				return strnatcasecmp($plugin1[0], $plugin2[0]);
			});

			$params['items'] = array_merge($params['items'], $elements);
		}

		return $params['items'];
	}

	static public function initializeFCEs($extensionKey) {
		if (isset(self::$fceConfiguration[$extensionKey])) {
			return;
		}

		$fceDir = ExtensionManagementUtility::extPath($extensionKey) . 'Configuration/FCE/';

		$pageTS = '';
		$typoScript = '';

		$defaults = include $fceDir . '_defaults.php';

		// Load all groups
		$groupsFile = ExtensionManagementUtility::extPath($extensionKey) . 'Configuration/FCE/_groups.php';
		if (file_exists($groupsFile)) {
			$groups = include $groupsFile;

			self::$groupNames = $groups + self::$groupNames;

			foreach ($groups as $key => $name) {
				$pageTS .= 'mod.wizards.newContentElement.wizardItems.' . $key . ' {' . "\n" .
				'	header = ' . $name . "\n" .
				'	show = *' . "\n" .
				'}' . "\n";
			}
		}

		$FCEs = [];

		// Load configs for FCEs
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fceDir, \FilesystemIterator::SKIP_DOTS)) as $fceConfigFile) {
			if ($fceConfigFile->isDir() || substr($fceConfigFile->getFilename(), 0, 1) == '_') {
				continue;
			}

			if (substr($fceConfigFile->getFilename(), -4) != '.php') {
				continue;
			}

			$config = include $fceConfigFile->getPathname();
			if (!$config) {
				continue;
			}
			$config = $config + $defaults;
			$config['filename'] = $fceConfigFile->getFilename();

			if (empty($config['group'])) {
				$config['group'] = 'vierwd';
			}

			$FCEs[] = $config;
		}

		usort($FCEs, function($FCE1, $FCE2) {
			return strcasecmp($FCE1['name'], $FCE2['name']);
		});

		// Process FCEs
		foreach ($FCEs as &$config) {
			if (!empty($config['pluginName'])) {
				// create a new plugin
				$pluginSignature = strtolower(str_replace('_', '', $extensionKey) . '_' . $config['pluginName']);
				if (empty($config['CType'])) {
					$config['CType'] = $pluginSignature;
				}

				$config['generatePlugin'] = true;
				$config['pluginSignature'] = $pluginSignature;
			}

			if (empty($config['CType'])) {
				throw new \Exception('Missing CType for ' . $config['filename']);
			}

			// update typoscript
			if ($config['template']) {
				$template = $config['template'];

				$templateDir = ExtensionManagementUtility::extPath($extensionKey) . 'Resources/Private/Templates/';
				if (substr($template, 0, 4) !== 'EXT:' && file_exists($templateDir . $template)) {
					$template = 'EXT:' . $extensionKey . '/Resources/Private/Templates/' . $template;
				}

				$typoScript .= 'tt_content.' . $config['CType'] . ' < plugin.tx_vierwdsmarty' . "\n";
				$typoScript .= 'tt_content.' . $config['CType'] . '.settings.template = ' . $template . "\n";

				$tcaType = GeneralUtility::trimExplode(',', $config['tcaType']);
				if (in_array('media', $tcaType)) {
					$typoScript .= 'tt_content.' . $config['CType'] . '.dataProcessing.10 = TYPO3\CMS\Frontend\DataProcessing\FilesProcessor' . "\n";
					$typoScript .= 'tt_content.' . $config['CType'] . '.dataProcessing.10.references.fieldName = assets' . "\n";
				} else if (in_array('image', $tcaType)) {
					$typoScript .= 'tt_content.' . $config['CType'] . '.dataProcessing.10 = TYPO3\CMS\Frontend\DataProcessing\FilesProcessor' . "\n";
					$typoScript .= 'tt_content.' . $config['CType'] . '.dataProcessing.10.references.fieldName = image' . "\n";
				}
			}

			if (is_array($config['group'])) {
				foreach ($config['group'] as $group) {
					self::$groups[$group][] = $config['CType'];
				}
			} else {
				self::$groups[$config['group']][] = $config['CType'];
			}
			unset($config);
		}

		$FCEs = self::validateFCEs($extensionKey, $FCEs);

		self::$fceConfiguration[$extensionKey] = [
			'typoScript' => $typoScript,
			'pageTS' => $pageTS,
			'FCEs' => $FCEs,
		];
	}

	/**
	 * validate FCEs and return only the valid FCEs.
	 * If the current request is not a clear cache request, this method will throw an exception if FCEs are invalid
	 */
	static protected function validateFCEs(string $extensionKey, array $FCEs): array {
		$extensionName = str_replace(' ', '', ucwords(str_replace('_', ' ', $extensionKey)));

		$currentPlugins = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extbase']['extensions'][$extensionName]['plugins'];
		$FCEs = array_filter($FCEs, function(array $config) use ($extensionKey, &$currentPlugins) {
			if ($config['generatePlugin']) {
				if (isset($currentPlugins[$config['pluginName']])) {
					// a plugin with the same name was added before
					self::generateException('Duplicate pluginName for extension ' . $extensionKey . ': ' . $config['pluginName'], 1482331342);
					return false;
				}
				$currentPlugins[$config['pluginName']] = true;

				// check controller names
				foreach (array_keys($config['controllerActions'] + $config['nonCacheableActions']) as $controllerName) {
					if (!preg_match('/^[A-Z]/', $controllerName)) {
						self::generateException('Controller name does not start with an uppercase letter. Extension ' . $extensionKey . '. Element: ' . $config['CType'], 1548429406);
						return false;
					}
				}
			}

			$name = $config['name'];
			if (!$name) {
				self::generateException('Missing FCE name for ' . $config['filename']);
				return false;
			}

			$tcaType = GeneralUtility::trimExplode(',', $config['tcaType']);
			if (in_array('image', $tcaType) && in_array('media', $tcaType)) {
				self::generateException('You can only choose either media or image as tcaType, but not both', 1491296754);
				return false;
			}

			return true;
		});

		return $FCEs;
	}

	/**
	 * If the current request is not a clear cache request, this method will throw an exception
	 */
	static protected function generateException(string $message, int $code) {
		if (!isset($_GET['cacheCmd']) || $_GET['cacheCmd'] !== 'all') {
			throw new \Exception($message, $code);
		}
	}

	/**
	 * add Content Elements
	 *
	 * @param string $extensionKey
	 */
	static public function addFCEs($extensionKey, $isLocalConf = false) {
		self::initializeFCEs($extensionKey);

		$typoScript = self::$fceConfiguration[$extensionKey]['typoScript'];
		$pageTS = self::$fceConfiguration[$extensionKey]['pageTS'];

		foreach (self::$fceConfiguration[$extensionKey]['FCEs'] as $config) {
			if ($config['generatePlugin'] && $isLocalConf) {
				ExtensionUtility::configurePlugin(
					'Vierwd.' . $extensionKey,
					$config['pluginName'],
					$config['controllerActions'],
					$config['nonCacheableActions'],
					ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT
				);

				if ($config['pluginSignature'] != $config['CType']) {
					// Copy from generated plugin without lib.stdheader
					$typoScript .= 'tmp < tt_content.' . $config['pluginSignature'] . ".20\n" .
						'tt_content.' . $config['CType'] . " < tmp\n" .
						"tmp >\n" .
						'tt_content.' . $config['pluginSignature'] . " >\n";
				} else {
					$typoScript .= 'tt_content.' . $config['CType'] . ' < tt_content.' . $config['pluginSignature'] . ".20\n";
				}
			}

			if (!$isLocalConf) {
				// ext_tables

				$name = $config['name'];

				ExtensionManagementUtility::addPlugin([$name, $config['CType'], $config['iconIdentifier']], 'CType', $extensionKey);
				$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes'][$config['CType']] = $config['iconIdentifier'];
				if ($config['adminOnly'] && is_array($GLOBALS['TCA']['tt_content']['columns'])) {
					$last = array_pop($GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items']);
					$last['adminOnly'] = true;
					$GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'][] = $last;
				}

				if ($config['flexform']) {
					if (substr($config['flexform'], 0, 5) !== 'FILE:') {
						$config['flexform'] = 'FILE:EXT:' . $extensionKey . '/Configuration/FlexForms/' . $config['flexform'];
					}
					ExtensionManagementUtility::addPiFlexFormValue('*', $config['flexform'], $config['CType']);
				}

				if (is_array($config['group'])) {
					foreach ($config['group'] as $group) {
						$pageTS .=
						'mod.wizards.newContentElement.wizardItems.' . $group . '.elements.' . $config['CType'] . ' {' . "\n" .
						'	iconIdentifier = ' . $config['iconIdentifier'] . "\n" .
						'	title = ' . $name . "\n" .
						'	description = ' . $config['description'] . "\n" .
						'	tt_content_defValues {' . "\n" .
						'		CType = ' . $config['CType'] . "\n" .
						'	}' . "\n" .
						'}' . "\n";
					}
				} else {
					$pageTS .=
					'mod.wizards.newContentElement.wizardItems.' . $config['group'] . '.elements.' . $config['CType'] . ' {' . "\n" .
					'	iconIdentifier = ' . $config['iconIdentifier'] . "\n" .
					'	title = ' . $name . "\n" .
					'	description = ' . $config['description'] . "\n" .
					'	tt_content_defValues {' . "\n" .
					'		CType = ' . $config['CType'] . "\n" .
					'	}' . "\n" .
					'}' . "\n";
				}
			}
		}

		if ($typoScript && $isLocalConf) {
			ExtensionManagementUtility::addTypoScript($extensionKey, 'setup', $typoScript, 'defaultContentRendering');
		}

		if ($pageTS && !$isLocalConf) {
			ExtensionManagementUtility::addPageTSConfig($pageTS);
		}
	}

	/**
	 * Generate TCA for FCEs.
	 * Gets called in TCA/Overrides/tt_content.php and will be cached.
	 *
	 * @param array $TCA
	 * @return array modified $TCA
	 */
	static public function addTCA($TCA) {
		$GLOBALS['TCA'] = $TCA;
		foreach (self::$fceConfiguration as $extensionKey => $configuration) {
			foreach ($configuration['FCEs'] as $config) {
				$tca = $config['fullTCA'] ?: self::generateTCA($config);

				if (ExtensionManagementUtility::isLoaded('gridelements') && strpos($tca, 'tx_gridelements_container, tx_gridelements_columns') === false) {
					$tca .= ', tx_gridelements_container, tx_gridelements_columns';
				}

				$GLOBALS['TCA']['tt_content']['types'][$config['CType']]['showitem'] = $tca;
				if (in_array('richtext', GeneralUtility::trimExplode(',', $config['tcaType']))) {
					$GLOBALS['TCA']['tt_content']['types'][$config['CType']]['columnsOverrides']['bodytext']['config']['enableRichtext'] = true;
				}

				foreach ($config['tcaAdditions'] as $tcaAddition) {
					$method = array_shift($tcaAddition);
					if ($method == 'addToAllTCAtypes') {
						ExtensionManagementUtility::addToAllTCAtypes('tt_content', $tcaAddition[0], $tcaAddition[1], $tcaAddition[2]);
					}
				}

				self::validateTCA($tca);
			}
		}

		return [$GLOBALS['TCA']];
	}

	static protected function validateTCA($tca) {
		$fields = GeneralUtility::trimExplode(',', $tca, true);
		foreach ($fields as $fieldString) {
			$fieldArray = GeneralUtility::trimExplode(';', $fieldString);
			$fieldArray = [
				'fieldName' => isset($fieldArray[0]) ? $fieldArray[0] : '',
				'fieldLabel' => isset($fieldArray[1]) ? $fieldArray[1] : null,
				'paletteName' => isset($fieldArray[2]) ? $fieldArray[2] : null,
			];
			if ($fieldArray['fieldName'] === '--palette--' && $fieldArray['paletteName'] !== null) {
				if (!isset($GLOBALS['TCA']['tt_content']['palettes'][$fieldArray['paletteName']])) {
					throw new \Exception('Missing palette: ' . $fieldArray['paletteName'], 1531385089);
				}
			}
		}
	}

	static public function generateTCA(array $config) {
		$tcaType = GeneralUtility::trimExplode(',', $config['tcaType']);

		// bodytext,richtext,image,fullheaders
		if (in_array('fullheaders', $tcaType)) {
			$header = '--palette--;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:palette.headers;headers,';
		} else if (in_array('simpleheaders', $tcaType)) {
			$header = '--palette--;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:palette.header;header,';
		} else {
			$header = 'header;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:header.ALT.div_formlabel,';
		}

		$bodytext = '';
		if (in_array('bodytext', $tcaType) || in_array('richtext', $tcaType)) {
			$bodytext = 'bodytext;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:bodytext_formlabel,';
		}

		if (in_array('media', $tcaType)) {
			$image = '--div--;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:tabs.media,
				assets,
				--palette--;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:palette.imagelinks;imagelinks,';
		} else if (in_array('image', $tcaType)) {
			$image = '--div--;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:tabs.images,
				image,
				--palette--;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:palette.imagelinks;imagelinks,';
		} else {
			$image = '';
		}
		if ($config['flexform']) {
			$flexform = 'pi_flexform,';
		} else {
			$flexform = '';
		}

		if (isset($GLOBALS['TCA']['tt_content']['palettes']['visibility'])) {
			$paletteVisibility = '--palette--;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:palette.visibility;visibility,';
		} else if (isset($GLOBALS['TCA']['tt_content']['palettes']['hidden'])) {
			$paletteVisibility = '--palette--;;hidden,';
		} else {
			$paletteVisibility = 'hidden,';
		}

		$tca = '
				--palette--;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:palette.general;general,
				' . $header . '
				' . $bodytext . '
				' . $flexform . '
				' . $image . '
			--div--;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:tabs.appearance,
				--palette--;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:palette.frames;frames,
			--div--;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:tabs.access,
				' . $paletteVisibility . '
				--palette--;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:palette.access;access,
			--div--;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:tabs.extended';

		return $tca;
	}

	/**
	 * add id of content element to first HTML Element.
	 * This enables direct links to elements.
	 * Normally TYPO3 would add those links with a link (<a id="cXX"></a>) or in the default wrapper,
	 * but this would interfere with :first-child pseudo elements
	 */
	public function elementUid($content, $params) {
		if (!$content || $content[0] != '<' || !$this->cObj || !$this->cObj->data['uid']) {
			return $content;
		}

		if ($GLOBALS['TSFE']->config['config']['tx_vierwd.']['disableElementId']) {
			return $content;
		}

		$additionalId = !empty($this->cObj->data['_LOCALIZED_UID']) && $this->cObj->data['_LOCALIZED_UID'] != $this->cObj->data['uid'];
		if ($additionalId) {
			$additionalIdAttr = ' id="c' . $this->cObj->data['_LOCALIZED_UID'] . '"';
			if (strpos($content, $additionalIdAttr) === false && $GLOBALS['TSFE']->config['config']['tx_vierwd.']['enableL10nAnchor']) {
				$additionalId = '<a' . $additionalIdAttr . '></a>';
			} else {
				$additionalId = false;
			}
		}

		// add uid to first element
		$idAttr = ' id="c' . $this->cObj->data['uid'] . '"';
		if (isset($this->cObj->data['parentData'], $this->cObj->data['parentData']['uid'])) {
			// this element is a reference. Make sure the ID does not appear twice on this page
			$idAttr = ' id="c' . $this->cObj->data['uid'] . '-' . $this->cObj->data['parentData']['uid'] . '"';
		}
		if (strpos($content, $idAttr) !== false) {
			return $additionalId . $content;
		}

		// no-cache elements (COA_INT and USER_INT are marked with <!--INT_SCRIPT.MD5-HASH--> and replaced later)
		// if the current content starts with a no-cache element, we cannot add the id to this element
		// Solution: Wrap the cache-marker
		$isINTIncScript = substr($content, 0, strlen('<!--INT_SCRIPT.')) === '<!--INT_SCRIPT.';
		if ($isINTIncScript) {
			return $additionalId . '<div' . $idAttr . '>' . $content . '</div>';
		}

		if (preg_match('/^<[^>]*\s+id=[^>]*>/', $content) || substr($content, 0, strlen('<!--')) === '<!--') {
			// id already present or comment -> add anchor before the element
			return $additionalId . '<a' . $idAttr . '></a>' . $content;
		}

		return $additionalId . preg_replace('/<(?!\\/)([^\s>!]+)/', '<$1' . $idAttr, $content, 1);
	}
}
