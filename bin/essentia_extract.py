"""Audio feature extractor for Soprano auto-playlist stations.

Reads audio file paths from stdin (one per line) and writes one JSON object
per line to stdout: the extracted features, or {"path": ..., "error": ...}
when a file can't be analyzed. Loading essentia is slow (~1s), so the batch
protocol keeps that cost to once per run instead of once per track.

Features are signal-level only (no classifier models): BPM, danceability,
key, energy, loudness, dynamic complexity, zero-crossing rate. BPM can
octave-jump (half/double time) — consumers should match tempo loosely.

Requires the essentia PyPI package (see php/Dockerfile).
"""
import sys
import json
import warnings

warnings.filterwarnings("ignore")

import essentia

essentia.log.infoActive = False
essentia.log.warningActive = False

import essentia.standard as es

VERSION = "essentia-2.1b6:1"
SAMPLE_RATE = 44100


def analyze(path):
    audio = es.MonoLoader(filename=path, sampleRate=SAMPLE_RATE)()
    if len(audio) == 0:
        raise ValueError("empty audio stream")

    bpm, _, _, _, _ = es.RhythmExtractor2013(method="multifeature")(audio)
    danceability, _ = es.Danceability()(audio)
    key_root, key_scale, key_strength = es.KeyExtractor()(audio)
    dyn_complexity, avg_loudness_db = es.DynamicComplexity()(audio)
    energy = es.Energy()(audio) / len(audio)
    zcr = es.ZeroCrossingRate()(audio)

    return {
        "path": path,
        "bpm": round(float(bpm), 2),
        "danceability": round(float(danceability), 3),
        "energy": round(float(energy), 8),
        "avg_loudness_db": round(float(avg_loudness_db), 2),
        "dyn_complexity": round(float(dyn_complexity), 3),
        "key_root": key_root,
        "key_scale": key_scale,
        "key_strength": round(float(key_strength), 3),
        "zcr": round(float(zcr), 5),
        "extractor": VERSION,
    }


def main():
    for line in sys.stdin:
        path = line.rstrip("\n")
        if path == "":
            continue
        try:
            result = analyze(path)
        except Exception as e:
            result = {"path": path, "error": str(e), "extractor": VERSION}
        print(json.dumps(result), flush=True)


if __name__ == "__main__":
    main()
