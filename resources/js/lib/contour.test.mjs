import assert from 'node:assert/strict';
import { test } from 'node:test';
import { CONTOUR, buildContour, buildPeakRings, quantizeRadius, ringPath, springScales, waveMask } from './contour.ts';

test('a ring is a closed path with the expected number of points', () => {
    const path = ringPath(100, 1, 1, 200);

    assert.ok(path.startsWith('M'));
    assert.ok(path.endsWith('Z'));
    assert.equal(path.split('L').length, 140);
});

test('rings are deterministic', () => {
    assert.equal(ringPath(300, 1.1, 0.9, 600), ringPath(300, 1.1, 0.9, 600));
});

test('the contour splits rings between the two breathing layers and keeps the heavy index lines', () => {
    const contour = buildContour(1200);
    const count = contour.even.length + contour.odd.length;

    assert.ok(count > 0 && count <= CONTOUR.lines);
    assert.equal(contour.even.length, Math.ceil(count / 2));
    assert.equal(contour.even[0].width, CONTOUR.weight * CONTOUR.indexWeight);
    assert.equal(contour.odd[0].width, CONTOUR.weight);
    assert.ok(contour.even[0].opacity > contour.even.at(-1).opacity, 'rings fade with distance');
});

test('rings beyond the field corners are skipped', () => {
    assert.ok(buildContour(400).even.length + buildContour(400).odd.length < buildContour(2000).even.length + buildContour(2000).odd.length);
});

test('the peak has the configured number of rings', () => {
    assert.equal(buildPeakRings().length, CONTOUR.peakRings);
});

test('the wave mask is a radial gradient with one band', () => {
    assert.match(waveMask(), /^radial-gradient\(circle closest-side,transparent 0%,transparent 80\.0%,#000 94\.0%,transparent 100\.0%\)$/);
});

test('the spring kicks to the configured force and settles near rest', () => {
    const scales = springScales();

    assert.equal(Math.max(...scales), Number((1 + CONTOUR.force / 100).toFixed(4)));
    assert.ok(Math.abs(scales.at(-1) - 1) < 0.001);
});

test('radii round up to the step', () => {
    assert.equal(quantizeRadius(1001), 1040);
    assert.equal(quantizeRadius(1000), 1000);
});
