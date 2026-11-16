<?php
namespace Latra\Application\AI;

interface AssistantLogReader { public function lines(int $limit,int $hours,?string $term): array; }
