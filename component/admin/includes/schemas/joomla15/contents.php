<?php
/**
 * @package     RedMIGRATOR.Backend
 * @subpackage  Controller
 *
 * @copyright   Copyright (C) 2005 - 2013 redCOMPONENT.com. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * 
 *  redMIGRATOR is based on JUpgradePRO made by Matias Aguirre
 */
/**
 * Upgrade class for content
 *
 * This class takes the content from the existing site and inserts them into the new site.
 *
 * @since  0.4.5
 */
class RedMigratorContent extends RedMigrator
{
	/**
	 * Get the raw data for this part of the upgrade.
	 *
	 * @return	array	Returns a reference to the source data array.
	 *
	 * @since	0.4.5
	 * @throws	Exception
	 */
	public function databaseHook($rows = null)
	{
		// Do some custom post processing on the list.
		foreach ($rows as &$row)
		{
			$row = (array) $row;

			$row['attribs'] = $this->convertParams($row['attribs']);
			$row['access'] = $row['access'] == 0 ? 1 : $row['access'] + 1;
			$row['language'] = '*';

			// Correct state
			if ($row['state'] == -1)
			{
				$row['state'] = 2;
			}

			// Prevent JGLOBAL_ARTICLE_MUST_HAVE_TEXT error
			if (trim($row['introtext']) == '' && trim($row['fulltext']) == '')
			{
				$row['introtext'] = '&nbsp;';
			}
		}

		return $rows;
	}

	/**
	 * A hook to be able to modify params prior as they are converted to JSON.
	 *
	 * @param	object	$object	A reference to the parameters as an object.
	 *
	 * @return	void
	 *
	 * @since	0.4.
	 * @throws	Exception
	 */
	protected function convertParamsHook(&$object)
	{
		$object->show_parent_category = isset($object->show_parent_category) ? $object->show_parent_category : "";
		$object->link_parent_category = isset($object->link_parent_category) ? $object->link_parent_category : "";
		$object->show_author = isset($object->show_author) ? $object->show_author : "";
		$object->link_author = isset($object->link_author) ? $object->link_author : "";
		$object->show_publish_date = isset($object->show_publish_date) ? $object->show_publish_date : "";
		$object->show_item_navigation = isset($object->show_item_navigation) ? $object->show_item_navigation : "";
		$object->show_print_icons = isset($object->show_print_icons) ? $object->show_print_icons : "";
		$object->show_icons = isset($object->show_icons) ? $object->show_icons : "";
		$object->show_vote = isset($object->show_vote) ? $object->show_vote : "";
		$object->show_hits = isset($object->show_hits) ? $object->show_hits : "";
		$object->show_noauth = isset($object->show_noauth) ? $object->show_noauth : "";
		$object->alternative_readmore = isset($object->alternative_readmore) ? $object->alternative_readmore : "";
		$object->article_layout = isset($object->article_layout) ? $object->article_layout : "";
		$object->show_publishing_options = isset($object->show_publishing_options) ? $object->show_publishing_options : "";
		$object->show_article_options = isset($object->show_article_options) ? $object->show_article_options : "";
		$object->show_urls_images_backend = isset($object->show_urls_images_backend) ? $object->show_urls_images_backend : "";
		$object->show_urls_images_frontend = isset($object->show_urls_images_frontend) ? $object->show_urls_images_frontend : "";

		// Component params
		$object->list_show_hits = isset($object->show_hits) ? $object->show_hits : "";
		$object->list_show_author = isset($object->show_author) ? $object->show_author : "";
		$object->show_readmore = isset($object->show_readmore) ? $object->show_readmore : "";

		unset($object->show_section);
		unset($object->link_section);
		unset($object->show_vote);
		unset($object->show_pdf_icon);
		unset($object->language);
		unset($object->keyref);
		unset($object->readmore);
		unset($object->urls_position);
		unset($object->feed_show_readmore);
	}

	/**
	 * Sets the data in the destination database.
	 *
	 * @return	void
	 *
	 * @since	0.5.3
	 *
	 * @throws	Exception
	 */
	public function dataHook($rows = null)
	{
		$params = $this->getParams();
		$table	= $this->getDestinationTable();

		// Get category mapping
		$query = "SELECT * FROM #__redmigrator_categories WHERE section REGEXP '^[\\-\\+]?[[:digit:]]*\\.?[[:digit:]]*$' AND old > 0";
		$this->_db->setQuery($query);
		$catidmap = $this->_db->loadObjectList('old');

		// Find uncategorised category id
		$query = "SELECT id FROM #__categories WHERE extension='com_content' AND path='uncategorised' LIMIT 1";
		$this->_db->setQuery($query);
		$defaultId = $this->_db->loadResult();

		// Initialize values
		$aliases = array();

		// Insert content data
		foreach ($rows as $row)
		{
			$row = (array) $row;

			// Check if title isn't blank
			$row['title'] = !empty($row['title']) ? $row['title'] : "###BLANK###";

			// Map catid
			$row['catid'] = isset($catidmap[$row['catid']]) ? $catidmap[$row['catid']]->new : $defaultId;

			// Aliases
			$row['alias'] = !empty($row['alias']) ? $row['alias'] : "###BLANK###";
			$row['alias'] = JApplication::stringURLSafe($row['alias']);

			// Check if has duplicated aliases
			$query = "SELECT alias FROM #__content"
						. " WHERE alias = " . $this->_db->quote($row['alias']);
			$this->_db->setQuery($query);
			$aliases = $this->_db->loadAssoc();

			$count = count($aliases);

			if ($count > 0)
			{
				$row['alias'] .= "-" . rand(0, 99999999);
			}

			// Setting the default rules
			$rules = array();
			$rules['core.delete'] = array('6' => true);
			$rules['core.edit'] = array('6' => true, '4' => 1);
			$rules['core.edit.state'] = array('6' => true, '5' => 1);
			$row['rules'] = $rules;

			// Converting the metadata to JSON
			if (version_compare(PHP_VERSION, '3.1', '>='))
			{
				$row['metadata'] = $row['metadata'] . "\ntags=";
			}

			$row['metadata'] = $this->convertParams($row['metadata'], false);

			// JTable:store() run an update if id exists into the object so we create them first
			$object = new stdClass;
			$object->id = $row['id'];

			// Inserting the content
			if (!$this->_db->insertObject($table, $object))
			{
				throw new Exception($this->_db->getErrorMsg());
			}

			// Getting the asset table
			$content = JTable::getInstance('Content', 'JTable', array('dbo' => $this->_db));

			// Bind data to save content
			if (!$content->bind($row))
			{
				echo $content->getError();
			}

			// Check the content
			if (!$content->check())
			{
				echo $content->getError();
			}

			// Insert the content
			if (!$content->store())
			{
				echo $content->getError();
			}

			// Updating the steps table
			$this->_step->_nextCID();
		}

		return false;
	}

	/**
	 * Run custom code after hooks
	 *
	 * @return	void
	 *
	 * @since	3.0.0
	 */
	public function afterHook()
	{
		$this->fixComponentConfiguration();
	}

	/**
	 * Upgrading the content configuration
	 */
	protected function fixComponentConfiguration()
	{
		if ($this->params->method == 'database')
		{
			$query = "SELECT params FROM #__components WHERE `option` = 'com_content'";
			$this->_driver->_db_old->setQuery($query);
			$articles_config = $this->_driver->_db_old->loadResult();

			// Check for query error.
			$error = $this->_driver->_db_old->getErrorMsg();

			if ($error)
			{
				throw new Exception($error);
			}

			// Convert params to JSON
			$articles_config = $this->convertParams($articles_config);

		}
		elseif ($this->params->method == 'rest')
		{
			$task = "tableparams";
			$table = "components";

			$articles_config = $this->_driver->requestRest($task, $table);
		}

		// Update the params on extensions table
		$query = "UPDATE #__extensions SET `params` = '{$articles_config}' WHERE `element` = 'com_content'";
		$this->_driver->_db->setQuery($query);
		$this->_driver->_db->query();

		// Check for query error.
		$error = $this->_driver->_db->getErrorMsg();

		if ($error)
		{
			throw new Exception($error);
		}
	}

	protected function updateFeature()
	{
		/*
		 * Update the featured column with records from content_frontpage FIXXXXXXXXXXXXXx
		 *
		$query = "UPDATE `#__content`, `{$this->config_old['prefix']}content_frontpage`"
		." SET `{$params->prefix_new}content`.featured = 1 WHERE `{$params->prefix_new}content`.id = `{$this->config_old['prefix']}content_frontpage`.content_id";
		$this->_db->setQuery($query);
		$this->_db->query();

		// Check for query error.
		$error = $this->_db->getErrorMsg();

		if ($error) {
			throw new Exception($error);
		}*/
	}
}
