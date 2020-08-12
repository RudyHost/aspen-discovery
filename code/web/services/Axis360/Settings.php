<?php

require_once ROOT_DIR . '/Action.php';
require_once ROOT_DIR . '/services/Admin/ObjectEditor.php';
require_once ROOT_DIR . '/sys/Axis360/Axis360Setting.php';

class Axis360_Settings extends ObjectEditor
{
	function getObjectType()
	{
		return 'Axis360Setting';
	}

	function getToolName()
	{
		return 'Settings';
	}

	function getModule()
	{
		return 'Axis360';
	}

	function getPageTitle()
	{
		return 'Axis 360 Settings';
	}

	function getAllObjects()
	{
		$object = new Axis360Setting();
		$object->find();
		$objectList = array();
		while ($object->fetch()) {
			$objectList[$object->id] = clone $object;
		}
		return $objectList;
	}

	function getObjectStructure()
	{
		return Axis360Setting::getObjectStructure();
	}

	function getPrimaryKeyColumn()
	{
		return 'id';
	}

	function getIdKeyColumn()
	{
		return 'id';
	}

	function getAllowableRoles()
	{
		return array('opacAdmin', 'libraryAdmin', 'cataloging', 'superCataloger');
	}

	function canAddNew()
	{
		return UserAccount::userHasRole('opacAdmin');
	}

	function canDelete()
	{
		return UserAccount::userHasRole('opacAdmin');
	}

	function getAdditionalObjectActions($existingObject)
	{
		return [];
	}

	function getInstructions()
	{
		return '';
	}
}