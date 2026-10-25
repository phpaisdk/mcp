<?php

declare(strict_types=1);

use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\FinishReason;
use AiSdk\Generate;
use AiSdk\Mcp\Client\Client;
use AiSdk\Mcp\Client\InMemoryTransport;
use AiSdk\Requests\TextModelRequest;
use AiSdk\Responses\Parts\TextPart;
use AiSdk\Responses\Parts\ToolCallPart;
use AiSdk\Responses\TextModelResponse;
use AiSdk\Support\Usage;

afterEach(fn() => Generate::reset());

it('lets a model call remote MCP tools through the normal Generate::text() tool loop', function () {
    $model = new class extends BaseModel implements TextModelInterface {
        /** @var list<TextModelRequest> */
        public array $requests = [];

        public function provider(): string
        {
            return 'scripted';
        }

        public function modelId(): string
        {
            return 'scripted-1';
        }

        public function generate(TextModelRequest $request): TextModelResponse
        {
            $this->requests[] = $request;

            if (count($this->requests) === 1) {
                return new TextModelResponse(
                    [new ToolCallPart('call_1', 'demo_greet', ['name' => 'Ada'])],
                    FinishReason::ToolCalls,
                    Usage::empty(),
                );
            }

            $toolMessage = $request->messages[array_key_last($request->messages)];

            return new TextModelResponse([new TextPart('Tool said: ' . $toolMessage->text())], FinishReason::Stop, Usage::empty());
        }

        public function stream(TextModelRequest $request): Generator
        {
            yield from [];
        }
    };

    $client = new Client(new InMemoryTransport(demoServer()));

    $result = Generate::text('Greet Ada')
        ->model($model)
        ->tools(...$client->tools(prefix: 'demo_'))
        ->maxSteps(3)
        ->run();

    expect($result->text)->toBe('Tool said: Hello, Ada!')
        ->and($result->toolResults)->toHaveCount(1)
        ->and($model->requests[0]->tools)->toHaveCount(3);
});
