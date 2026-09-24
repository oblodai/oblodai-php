<?php

// A mock Oblodai gateway for `php -S 127.0.0.1:<port> tests/Support/mock-gateway.php`: any route of
// the generated table answers with a minimal valid body for the model its generated method parses
// (a document route with a PDF), so the README and the examples run end to end. A few answers are
// shaped (MockGateway::SHAPES) so the scripts walk their main path instead of an early exit.
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Oblodai\Tests\Support\MockGateway;

MockGateway::serve();
