<?php

declare(strict_types=1);

namespace OCA\Music\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add artist_mbids to music_tracks table
 */
class Version030201Date20261017000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$tracks = $schema->getTable('music_tracks');
		if (!$tracks->hasColumn('artist_mbids')) {
			$tracks->addColumn('artist_mbids', Types::TEXT, ['notnull' => false]);
		}

		return $schema;
	}
}
