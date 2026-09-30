<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Search;

interface SearchProvider
{
    public function search(SearchRequest $request): SearchResponse;
}
