<?php

declare(strict_types=1);

namespace Tests\Soprano;

use App\Services\Soprano\TranscodeService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage for the data-saver shrink threshold: parsing the
 * configured ffmpeg bitrate, and deciding whether re-encoding an
 * already-compressed source actually saves enough bytes to be worth it.
 * The ffmpeg/cache side is exercised against real files by
 * `soprano:transcode`.
 */
class TranscodeServiceTest extends TestCase
{
    /** Five minutes, the duration every sizing case below is measured at. */
    private const FIVE_MIN_MS = 300_000;

    public function testParseBitrateHandlesEveryFfmpegSuffix(): void
    {
        $this->assertSame(128000, TranscodeService::parseBitrate('128k'));
        $this->assertSame(128000, TranscodeService::parseBitrate('128K'));
        $this->assertSame(96000, TranscodeService::parseBitrate('96000'));
        $this->assertSame(1000000, TranscodeService::parseBitrate('1M'));
        $this->assertSame(1500, TranscodeService::parseBitrate('1.5k'));
        $this->assertSame(128000, TranscodeService::parseBitrate('  128k  '));
    }

    public function testParseBitrateNeverGoesNegativeOrThrowsOnGarbage(): void
    {
        $this->assertSame(0, TranscodeService::parseBitrate(''));
        $this->assertSame(0, TranscodeService::parseBitrate('k'));
        $this->assertSame(0, TranscodeService::parseBitrate('nonsense'));
        $this->assertSame(0, TranscodeService::parseBitrate('-128k'));
    }

    /** A 320kbps MP3 is ~2.5x its own 128k encode — the case data saver exists for. */
    public function testHighBitrateLossySourceIsWorthShrinking(): void
    {
        $bytes = (int) ((320000 / 8) * 300);

        $this->assertTrue(
            TranscodeService::shrinkWorthwhile($bytes, self::FIVE_MIN_MS, 128000),
        );
    }

    /** A 96kbps MP3 is *smaller* than the 128k encode; re-encoding would grow it. */
    public function testLowBitrateLossySourceIsLeftAlone(): void
    {
        $bytes = (int) ((96000 / 8) * 300);

        $this->assertFalse(
            TranscodeService::shrinkWorthwhile($bytes, self::FIVE_MIN_MS, 128000),
        );
    }

    /**
     * The margin is a floor, not a hair-trigger: a source only barely bigger
     * than its encode doesn't pay for a second lossy generation.
     */
    public function testMarginIsRequiredNotJustAnyIncrease(): void
    {
        $encoded = (128000 / 8) * 300;

        $justUnder = (int) floor($encoded * (TranscodeService::SHRINK_MARGIN - 0.01));
        $atMargin  = (int) ceil($encoded * TranscodeService::SHRINK_MARGIN);

        $this->assertFalse(TranscodeService::shrinkWorthwhile($justUnder, self::FIVE_MIN_MS, 128000));
        $this->assertTrue(TranscodeService::shrinkWorthwhile($atMargin, self::FIVE_MIN_MS, 128000));
    }

    /** Missing duration, size or bitrate means no answer — never encode blind. */
    public function testUnknownInputsLeaveTheSourceAlone(): void
    {
        $big = 50_000_000;

        $this->assertFalse(TranscodeService::shrinkWorthwhile($big, 0, 128000));
        $this->assertFalse(TranscodeService::shrinkWorthwhile($big, -1, 128000));
        $this->assertFalse(TranscodeService::shrinkWorthwhile(0, self::FIVE_MIN_MS, 128000));
        $this->assertFalse(TranscodeService::shrinkWorthwhile($big, self::FIVE_MIN_MS, 0));
    }
}
