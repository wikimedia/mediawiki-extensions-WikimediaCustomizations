<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Attribution;

/**
 * Result of a reference count operation.
 * Includes the reference count, source of this information and operation result which will provide
 * basic information about the operation, e.g. whether the operation was a cache hit, miss or error.
 */
class ReferenceCountResult {

	public const string CACHE_HIT = 'cache_hit';
	public const string CACHE_MISS = 'cache_miss';
	public const string ERROR = 'error';

	public function __construct(
		private readonly ?int $referenceCount,
		private readonly string $source,
		private readonly string $operationResult
	) {
	}

	public function getReferenceCount(): ?int {
		return $this->referenceCount;
	}

	public function getSource(): string {
		return $this->source;
	}

	public function getOperationResult(): string {
		return $this->operationResult;
	}
}
