<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\KakaoChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The KakaoTalk channel buttons.
 *
 * Two properties matter more than the markup. An install with no channel
 * configured must draw no button at all, because a dead Kakao button on the
 * login page is worse than none. And with a channel but no JavaScript key the
 * buttons must still work: they are pf.kakao.com links first, and the SDK only
 * ever upgrades them.
 */
class KakaoChannelTest extends TestCase
{
    use RefreshDatabase;

    private function configure(?string $channel = '_abcdef', ?string $key = null): void
    {
        config([
            'services.kakao.channel_id' => $channel,
            'services.kakao.javascript_key' => $key,
        ]);
    }

    #[Test]
    public function nothing_is_drawn_when_no_channel_is_configured(): void
    {
        $this->configure(channel: null);

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('pf.kakao.com')
            ->assertDontSee('kakao_js_sdk');
    }

    #[Test]
    public function a_blank_channel_id_counts_as_unconfigured(): void
    {
        // Laravel hands back '' for an env line left empty, which is the state
        // every install starts in.
        $this->configure(channel: '   ');

        $this->assertFalse(KakaoChannel::isConfigured());
        $this->assertNull(KakaoChannel::url('chat'));
    }

    #[Test]
    public function the_channel_id_alone_gives_a_working_link(): void
    {
        $this->configure();

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('https://pf.kakao.com/_abcdef/chat')
            // No key, so no third-party script is pulled in.
            ->assertDontSee('kakao_js_sdk');
    }

    #[Test]
    public function the_javascript_key_adds_the_sdk_and_keeps_the_link(): void
    {
        $this->configure(key: 'js-key-here');

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('kakao_js_sdk/2.8.1/kakao.min.js', escape: false)
            ->assertSee('js-key-here', escape: false)
            ->assertSee('https://pf.kakao.com/_abcdef/chat');
    }

    #[Test]
    public function the_sdk_is_loaded_once_however_many_buttons_a_page_has(): void
    {
        $this->configure(key: 'js-key-here');

        // The landing page carries the chat button and the add-channel button.
        $body = $this->get(route('home'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($body, 'kakao_js_sdk/2.8.1/kakao.min.js'));
        $this->assertSame(1, substr_count($body, 'Kakao.init('));
        // The attribute with a value — the bare name also appears in the
        // delegated listener's selector.
        $this->assertSame(2, substr_count($body, 'data-kakao-channel="'));
        $this->assertStringContainsString('data-kakao-channel="chat"', $body);
        $this->assertStringContainsString('data-kakao-channel="add"', $body);
    }

    #[Test]
    public function each_action_points_at_its_own_url(): void
    {
        $this->configure();

        $this->assertSame('https://pf.kakao.com/_abcdef/chat', KakaoChannel::url('chat'));
        // Add and follow land on the channel home, which carries Kakao's own
        // add control — a path certain to exist beats a guessed deep link.
        $this->assertSame('https://pf.kakao.com/_abcdef', KakaoChannel::url('add'));
        $this->assertSame('https://pf.kakao.com/_abcdef', KakaoChannel::url('follow'));
        $this->assertNull(KakaoChannel::url('nonsense'));
    }

    #[Test]
    public function a_channel_id_is_escaped_into_the_url(): void
    {
        $this->configure(channel: '_ab cd/../evil');

        $this->assertSame(
            'https://pf.kakao.com/_ab%20cd%2F..%2Fevil/chat',
            KakaoChannel::url('chat'),
        );
    }

    #[Test]
    public function the_signed_in_shell_carries_the_chat_button(): void
    {
        $this->configure();

        $this->actingAs(User::factory()->instructor()->create())
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertSee('https://pf.kakao.com/_abcdef/chat');
    }

    #[Test]
    public function the_button_is_left_off_a_printed_page(): void
    {
        // Chrome, like the sidebar and the header: a payslip printed from the
        // earnings page must not carry a chat button across it.
        $this->configure();

        $this->actingAs(User::factory()->instructor()->create())
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertSee('no-print fixed bottom-5 right-5');
    }
}
