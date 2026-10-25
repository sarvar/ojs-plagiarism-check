<?php

/**
 * @file plugins/generic/plagiarismCheck/grids/MoveColumnFeature.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class MoveColumnFeature
 * @ingroup plugins_generic_plagiarismCheck
 *
 * @brief Columns added from the initFeatures hook end up first in the grid.
 *  Move the similarity column after the file name column instead.
 */

import('lib.pkp.classes.controllers.grid.feature.GridFeature');

class MoveColumnFeature extends GridFeature {

	/** @var GridHandler */
	protected $_grid;

	/**
	 * @param GridHandler $grid
	 */
	public function __construct($grid) {
		$this->_grid = $grid;
		parent::__construct('plagiarismCheckMoveColumn');
	}

	/**
	 * @copydoc GridFeature::getGridDataElements()
	 */
	public function getGridDataElements($args) {
		$columns =& $this->_grid->_columns;
		// The hook also runs while the grid is still initializing, before its
		// own columns exist; wait until there is a column to move after.
		if (count($columns) < 2 || array_key_first($columns) !== SimilarityGridColumn::COLUMN_ID) {
			return;
		}
		$column = $columns[SimilarityGridColumn::COLUMN_ID];
		unset($columns[SimilarityGridColumn::COLUMN_ID]);
		$column->addFlag('firstColumn', false);
		$firstKey = array_key_first($columns);
		$columns = array_slice($columns, 0, 1, true)
			+ [SimilarityGridColumn::COLUMN_ID => $column]
			+ array_slice($columns, 1, null, true);
		$columns[$firstKey]->addFlag('firstColumn', true);
	}
}
