<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests;

use MediaWiki\Tests\Structure\OwnersStructureTestBase;

/**
 * @coversNothing
 */
class OwnersStructureTest extends OwnersStructureTestBase {
	/**
	 * @inheritDoc
	 */
	public function getUntestedFiles(): array {
		return array_merge(
			parent::getUntestedFiles(),
			[
				'tests/phpunit/structure/OwnersStructureTest.php',
				'/src/ServiceWiring.php',
			]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function getOwnersFile(): string {
		return __DIR__ . '/../../../OWNERS.md';
	}

	/**
	 * @inheritDoc
	 */
	public function getFolders(): array {
		return [ 'tests', 'src' ];
	}
}
