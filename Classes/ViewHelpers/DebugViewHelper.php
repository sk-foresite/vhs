<?php
namespace FluidTYPO3\Vhs\ViewHelpers;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2015 Claus Due, Wildside A/S <claus@namelesscoder.net>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
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
 * ************************************************************* */

use TYPO3\CMS\Extbase\Reflection\Exception\PropertyNotAccessibleException;
use TYPO3\CMS\Fluid\Core\Parser\SyntaxTree\ObjectAccessorNode;
use TYPO3\CMS\Fluid\Core\Parser\SyntaxTree\ViewHelperNode;
use TYPO3\CMS\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3\CMS\Fluid\Core\ViewHelper\Facets\ChildNodeAccessInterface;
use TYPO3\CMS\Extbase\Reflection\ObjectAccess;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

/**
 * ### ViewHelper Debug ViewHelper (sic)
 *
 * Debugs instances of other ViewHelpers and language
 * structures. Use in conjunction with other ViewHelpers
 * to inspect their current and possible arguments and
 * render their documentation:
 *
 *     <v:debug><f:format.html>{variable}</f:format.html></v:debug>
 *
 * Or the same expression in inline syntax:
 *
 *     {variable -> f:format.html() -> v:debug()}
 *
 * Can also be used to inspect `ObjectAccessor` instances
 * (e.g. variables you try to access) and rather than just
 * dumping the entire contents of the variable as is done
 * by `<f:debug />`, this ViewHelper makes a very simple
 * dump with a warning if the variable is not defined. If
 * an object is encountered (for example a domain object)
 * this ViewHelper will not dump the object but instead
 * will scan it for accessible properties (e.g. properties
 * which have a getter method!) and only present those
 * properties which can be accessed, along with the type
 * of variable that property currently contains:
 *
 *     {domainObject -> v:debug()}
 *
 * Assuming that `{domainObject}` is an instance of an
 * object which has two methods: `getUid()` and `getTitle()`,
 * debugging that instance will render something like this
 * in plain text:
 *
 *     Path: {domainObject}
 *     Value type: object
 *     Accessible properties on {domainObject}:
 *        {form.uid} (integer)
 *        {form.title} (string)
 *
 * The class itself can contain any number of protected
 * properties, but only those which have a getter method
 * can be accessed by Fluid and as therefore we only dump
 * those properties which you **can in fact access**.
 *
 * @package Vhs
 * @subpackage ViewHelpers
 */
class DebugViewHelper extends AbstractViewHelper implements ChildNodeAccessInterface {

	/**
	 * @var ViewHelperNode[]
	 */
	protected $childViewHelperNodes = array();

	/**
	 * @var ObjectAccessorNode[]
	 */
	protected $childObjectAccessorNodes = array();
	/**
	 * With this flag, you can disable the escaping interceptor inside this ViewHelper.
	 * THIS MIGHT CHANGE WITHOUT NOTICE, NO PUBLIC API!
	 * @var boolean
	 */
	protected $escapingInterceptorEnabled = FALSE;
	/**
	 * @return string
	 */
	public function render() {
		$nodes = array();
		foreach ($this->childViewHelperNodes as $viewHelperNode) {
			$viewHelper = $viewHelperNode->getUninitializedViewHelper();
			$arguments = $viewHelper->prepareArguments();
			$givenArguments = $viewHelperNode->getArguments();
			$viewHelperReflection = new \ReflectionClass($viewHelper);
			$viewHelperDescription = $viewHelperReflection->getDocComment();
			$viewHelperDescription = htmlentities($viewHelperDescription);
			$viewHelperDescription = '[CLASS DOC]' . LF . $viewHelperDescription . LF;
			$renderMethodDescription = $viewHelperReflection->getMethod('render')->getDocComment();
			$renderMethodDescription = htmlentities($renderMethodDescription);
			$renderMethodDescription = implode(LF, array_map('trim', explode(LF, $renderMethodDescription)));
			$renderMethodDescription = '[RENDER METHOD DOC]' . LF . $renderMethodDescription . LF;
			$argumentDefinitions = array();
			foreach ($arguments as &$argument) {
				$name = $argument->getName();
				$argumentDefinitions[$name] = ObjectAccess::getGettableProperties($argument);
			}
			$sections = array(
				$viewHelperDescription,
				DebuggerUtility::var_dump($argumentDefinitions, '[ARGUMENTS]', 4, TRUE, FALSE, TRUE),
				DebuggerUtility::var_dump($givenArguments, '[CURRENT ARGUMENTS]', 4, TRUE, FALSE, TRUE),
				$renderMethodDescription
			);
			array_push($nodes, implode(LF, $sections));

		}
		if (0 < count($this->childObjectAccessorNodes)) {
			array_push($nodes, '[VARIABLE ACCESSORS]');
			$templateVariables = $this->templateVariableContainer->getAll();
			foreach ($this->childObjectAccessorNodes as $objectAccessorNode) {
				$path = $objectAccessorNode->getObjectPath();
				$segments = explode('.', $path);
				try {
					$value = ObjectAccess::getProperty($templateVariables, array_shift($segments));
					foreach ($segments as $segment) {
						$value = ObjectAccess::getProperty($value, $segment);
					}
					$type = gettype($value);
				} catch (PropertyNotAccessibleException $error) {
					$value = NULL;
					$type = 'UNDEFINED/INACCESSIBLE';
				}
				$sections = array(
					'Path: {' . $path . '}',
					'Value type: ' . $type,
				);
				if (TRUE === is_object($value)) {
					$sections[] = 'Accessible properties on {' . $path . '}:';
					$gettable = ObjectAccess::getGettablePropertyNames($value);
					unset($gettable[0]);
					foreach ($gettable as $gettableProperty) {
						$sections[] = '   {' . $path . '.' . $gettableProperty . '} (' . gettype(ObjectAccess::getProperty($value, $gettableProperty)) . ')';
					}
				} elseif (NULL !== $value) {
					$sections[] = DebuggerUtility::var_dump($value, 'Dump of variable "' . $path . '"', 4, TRUE, FALSE, TRUE);
				}
				array_push($nodes, implode(LF, $sections));
			}


		}
		return '<pre>' . implode(LF . LF, $nodes) . '</pre>';
	}

	/**
	 * Sets the direct child nodes of the current syntax tree node.
	 *
	 * @param \TYPO3\CMS\Fluid\Core\Parser\SyntaxTree\AbstractNode[] $childNodes
	 * @return void
	 */
	public function setChildNodes(array $childNodes) {
		foreach ($childNodes as $childNode) {
			if (TRUE === $childNode instanceof ViewHelperNode) {
				array_push($this->childViewHelperNodes, $childNode);
			}
			if (TRUE === $childNode instanceof ObjectAccessorNode) {
				array_push($this->childObjectAccessorNodes, $childNode);
			}
		}
	}

}
