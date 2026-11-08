<?php

namespace Tests\Feature;

use App\Models\Cv;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CvQuotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_paid_cv_can_be_exported_only_twice_per_payment(): void
    {
        [$user, $cv] = $this->createPaidCv();

        $this->getJson("/api/v1/cvs/{$cv->id}/export-permission")
            ->assertOk()
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('prints_remaining', 2);

        $this->postJson("/api/v1/cvs/{$cv->id}/export")->assertOk();
        $this->postJson("/api/v1/cvs/{$cv->id}/export")->assertOk();
        $this->postJson("/api/v1/cvs/{$cv->id}/export")->assertStatus(402);

        $this->assertSame(2, $cv->fresh()->print_count);
        $this->assertSame(0, $cv->fresh()->modification_count);
        $this->postJson("/api/v1/cvs/{$cv->id}/modification-session")
            ->assertOk()
            ->assertJsonPath('allowed', true);
    }

    public function test_one_modification_session_can_save_multiple_times_but_cannot_be_reopened(): void
    {
        [, $cv] = $this->createPaidCv();

        $session = $this->postJson("/api/v1/cvs/{$cv->id}/modification-session")
            ->assertOk()
            ->assertJsonPath('allowed', true)
            ->json('session_id');

        $this->putJson("/api/v1/cvs/{$cv->id}", $this->payload($cv, 'Premier changement', $session))
            ->assertOk();
        $this->putJson("/api/v1/cvs/{$cv->id}", $this->payload($cv->fresh(), 'Deuxième sauvegarde', $session))
            ->assertOk();

        $this->assertSame(1, $cv->fresh()->modification_count);
        $this->getJson("/api/v1/cvs/{$cv->id}/export-permission")
            ->assertOk()
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('modification_allowed', false);

        $this->deleteJson("/api/v1/cvs/{$cv->id}/modification-session", ['session_id' => $session])
            ->assertOk();
        $this->postJson("/api/v1/cvs/{$cv->id}/modification-session")
            ->assertStatus(402);
        $this->putJson("/api/v1/cvs/{$cv->id}", $this->payload($cv->fresh(), 'Changement refusé'))
            ->assertStatus(402);
    }

    public function test_a_duplicated_cv_does_not_inherit_the_original_payment_or_quotas(): void
    {
        [, $cv] = $this->createPaidCv();
        $cv->update(['print_count' => 2, 'modification_count' => 1]);

        $copy = $this->postJson("/api/v1/cvs/{$cv->id}/duplicate")
            ->assertCreated()
            ->json('cv');

        $this->assertNull($copy['export_paid_at']);
        $this->assertSame(0, $copy['print_count']);
        $this->assertSame(0, $copy['modification_count']);
    }

    public function test_ats_analysis_uses_the_original_extracted_cv_text(): void
    {
        [, $cv] = $this->createPaidCv();
        $cv->update([
            'content' => [
                'profile' => '',
                'experiences' => [],
                'source_text' => 'Développeur avec cinq ans d’expérience Laravel et PHP.',
            ],
        ]);

        $this->postJson('/api/v1/cv/analyze', [
            'cv_id' => $cv->id,
            'job_offer' => 'Nous recherchons une personne avec une expérience Laravel et PHP.',
        ])
            ->assertOk()
            ->assertJsonFragment(['laravel']);
    }

    private function createPaidCv(): array
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => Str::uuid().'@example.test',
            'password' => 'password',
        ]);
        $cv = $user->cvs()->create([
            'title' => 'CV test',
            'template' => 'moderne',
            'content' => ['profile' => 'Avant'],
            'export_paid_at' => now(),
            'print_count' => 0,
            'modification_count' => 0,
        ]);

        Sanctum::actingAs($user);

        return [$user, $cv];
    }

    private function payload(Cv $cv, string $profile, ?string $sessionId = null): array
    {
        return [
            'title' => $cv->title,
            'template' => $cv->template,
            'color' => $cv->color,
            'content' => ['profile' => $profile],
            ...($sessionId ? ['modification_session' => $sessionId] : []),
        ];
    }
}
