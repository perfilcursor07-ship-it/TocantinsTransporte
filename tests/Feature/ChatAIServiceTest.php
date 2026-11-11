<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Payment;
use App\Models\User;
use App\Services\ChatAIService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class ChatAIServiceTest extends TestCase
{
    public function createApplication()
    {
        $app = require __DIR__ . '/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'session.driver' => 'array',
            'logging.default' => 'null',
        ]);
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('mac_address')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('status')->default('pending');
            $table->string('role')->default('user');
            $table->string('last_mikrotik_id')->nullable();
            $table->dateTime('connected_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->decimal('amount', 10, 2);
            $table->string('payment_type');
            $table->string('status');
            $table->string('bus_mikrotik_id')->nullable();
            $table->json('payment_data')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_name');
            $table->string('visitor_phone');
            $table->string('visitor_email')->nullable();
            $table->string('visitor_ip')->nullable();
            $table->string('visitor_mac')->nullable();
            $table->string('session_id')->unique();
            $table->string('status')->default('active');
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->dateTime('last_message_at')->nullable();
            $table->integer('unread_count')->default(0);
            $table->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->string('sender_type');
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->text('message');
            $table->string('type')->default('text');
            $table->json('metadata')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
    }

    public function test_no_payment_is_explained_as_no_active_payment(): void
    {
        [$conversation] = $this->conversation('Selma Lima Oliveira', '63999990001');
        $this->visitorMessage($conversation, 'Nao estou conseguindo acesso a Internet');

        $reply = app(ChatAIService::class)->respond($conversation);

        $this->assertSame('text', $reply->type);
        $this->assertStringContainsString('não encontrei pagamento ativo', $reply->message);
        $this->assertStringContainsString('necessário fazer um novo pagamento', $reply->message);
        $this->assertStringNotContainsString('cadastro expirado', mb_strtolower($reply->message));
    }

    public function test_paid_claim_without_active_payment_requests_receipt_immediately(): void
    {
        [$conversation] = $this->conversation('João', '63999990002');
        $this->visitorMessage($conversation, 'Paguei a internet pelo 4g, mas não deu certo');

        $reply = app(ChatAIService::class)->respond($conversation);

        $this->assertSame('receipt_request', $reply->type);
        $this->assertStringContainsString('não aparece como ativo', $reply->message);
        $this->assertStringContainsString('comprovante', $reply->message);
        $this->assertStringNotContainsString('você já pagou', mb_strtolower($reply->message));
    }

    public function test_bank_without_internet_gets_two_stage_pix_instructions(): void
    {
        [$conversation] = $this->conversation('Kathleen', '63999990003');
        $this->visitorMessage($conversation, 'Quero colocar só que não tenho Internet pra abrir o banco');

        $reply = app(ChatAIService::class)->respond($conversation);

        $this->assertSame('text', $reply->type);
        $this->assertStringContainsString('copie o código PIX', $reply->message);
        $this->assertStringContainsString('ligue o 4G e pague no banco', $reply->message);
        $this->assertStringContainsString('sem esquecer a rede', mb_strtolower($reply->message));
    }

    public function test_device_answer_does_not_request_mac_without_valid_payment(): void
    {
        [$conversation] = $this->conversation('João', '63999990004');
        $this->assistantMessage($conversation, 'Você está usando iOS (iPhone) ou Android?');
        $this->visitorMessage($conversation, 'iOS');

        $reply = app(ChatAIService::class)->respond($conversation);

        $this->assertSame('text', $reply->type);
        $this->assertStringContainsString('não encontrei pagamento ativo', $reply->message);
    }

    public function test_device_answer_can_request_mac_when_payment_is_valid(): void
    {
        [$conversation, $user] = $this->conversation('João', '63999990005');
        Payment::create([
            'user_id' => $user->id,
            'amount' => 6.99,
            'payment_type' => 'pix',
            'status' => 'completed',
            'paid_at' => now(),
            'payment_data' => ['duration_hours' => 12],
        ]);
        $this->assistantMessage($conversation, 'Você está usando iOS (iPhone) ou Android?');
        $this->visitorMessage($conversation, 'iOS');

        $reply = app(ChatAIService::class)->respond($conversation);

        $this->assertSame('mac_request', $reply->type);
        $this->assertStringContainsString('MAC da rede deste iPhone', $reply->message);
    }

    public function test_typed_mac_that_matches_system_gets_private_dns_steps(): void
    {
        Http::fake();
        [$conversation] = $this->macConversation('63999990006', '52:3F:15:11:AF:AE');
        $this->visitorMessage($conversation, '52:3f:15:11:af:ae');

        $reply = app(ChatAIService::class)->respond($conversation);

        $this->assertSame('text', $reply->type);
        $this->assertEmpty($reply->metadata['escalated'] ?? null);
        $this->assertStringContainsString('é o mesmo que está liberado', $reply->message);
        $this->assertStringContainsString('DNS privado', $reply->message);
        $this->assertStringContainsString('Automático', $reply->message);
        $this->assertStringContainsString('não toque em Esquecer a rede', $reply->message);
    }

    public function test_typed_mac_different_from_system_escalates_to_human(): void
    {
        Http::fake();
        [$conversation] = $this->macConversation('63999990007', '52:3F:15:11:AF:AE');
        $this->visitorMessage($conversation, 'AA:BB:CC:DD:EE:FF');

        $reply = app(ChatAIService::class)->respond($conversation);

        $this->assertTrue($reply->metadata['escalated']);
        $this->assertStringContainsString('diferente do que está liberado', $reply->message);
        $this->assertStringContainsString('52:3F:15:11:AF:AE', $reply->metadata['reason']);
        $this->assertSame('pending', $conversation->fresh()->status);
    }

    public function test_private_dns_steps_failed_escalates_to_human(): void
    {
        Http::fake();
        [$conversation] = $this->macConversation('63999990008', '52:3F:15:11:AF:AE');
        $this->visitorMessage($conversation, '52:3F:15:11:AF:AE');
        app(ChatAIService::class)->respond($conversation);
        $this->visitorMessage($conversation, 'Fiz tudo e ainda não funcionou');

        $reply = app(ChatAIService::class)->respond($conversation);

        $this->assertTrue($reply->metadata['escalated']);
        $this->assertStringContainsString('DNS privado', $reply->metadata['reason']);
    }

    public function test_unreadable_mac_photo_asks_to_type_before_escalating(): void
    {
        Http::fake();
        [$conversation] = $this->macConversation('63999990009', '52:3F:15:11:AF:AE');
        $service = app(ChatAIService::class);

        $first = $service->handleCollectedMac($conversation, null);
        $second = $service->handleCollectedMac($conversation, null);

        $this->assertStringContainsString('não consegui ler o MAC', $first->message);
        $this->assertEmpty($first->metadata['escalated'] ?? null);
        $this->assertTrue($second->metadata['escalated']);
        $this->assertStringContainsString('DNS privado', $second->message);
    }

    public function test_reads_mac_from_photo_with_vision_model(): void
    {
        config([
            'services.together.enabled' => true,
            'services.together.api_key' => 'test-key',
            'services.together.vision_model' => 'vision-model',
        ]);
        Http::fake([
            '*' => Http::response(['choices' => [['message' => ['content' => '52:3f:15:11:af:ae']]]]),
        ]);
        $image = tempnam(sys_get_temp_dir(), 'mac');
        file_put_contents($image, 'fake-image');

        $mac = app(ChatAIService::class)->readMacFromImage($image, 'image/png');
        unlink($image);

        $this->assertSame('52:3F:15:11:AF:AE', $mac);
        Http::assertSent(fn ($request) => $request['model'] === 'vision-model'
            && str_starts_with($request['messages'][0]['content'][1]['image_url']['url'], 'data:image/png;base64,'));
    }

    /**
     * Passageiro com acesso ativo que já respondeu "Android" e recebeu o pedido de MAC.
     */
    private function macConversation(string $phone, string $liberatedMac): array
    {
        [$conversation, $user] = $this->conversation('Maria', $phone);
        $user->update([
            'mac_address' => $liberatedMac,
            'status' => 'connected',
            'expires_at' => now()->addHours(10),
        ]);
        $this->assistantMessage($conversation, 'Você está usando iOS (iPhone) ou Android?');
        $this->visitorMessage($conversation, 'Android');
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'admin',
            'type' => 'mac_request',
            'message' => 'Manda a foto do MAC',
            'metadata' => ['ai' => true],
        ]);

        return [$conversation, $user];
    }

    private function conversation(string $name, string $phone): array
    {
        $user = User::create([
            'name' => $name,
            'phone' => $phone,
            'status' => 'pending',
            'expires_at' => now()->subHour(),
        ]);

        $conversation = ChatConversation::create([
            'visitor_name' => $name,
            'visitor_phone' => $phone,
            'session_id' => fake()->uuid(),
            'status' => 'active',
            'last_message_at' => now(),
        ]);

        return [$conversation, $user];
    }

    private function visitorMessage(ChatConversation $conversation, string $message): void
    {
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'visitor',
            'message' => $message,
        ]);
    }

    private function assistantMessage(ChatConversation $conversation, string $message): void
    {
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'admin',
            'message' => $message,
            'metadata' => ['ai' => true],
        ]);
    }
}
