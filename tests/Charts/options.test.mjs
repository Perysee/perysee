import { test } from 'node:test';
import assert from 'node:assert/strict';
import { lineOption } from '../../resources/js/chart-options.mjs';
test('sessions retain unavailable gaps and exact chart values', () => {
    const option = lineOption([{label:'00:00 CEST',sessions:4},{label:'02:00 CET',sessions:null},{label:'03:00 CET',sessions:2}], 'sessions');
    assert.deepEqual(option.series[0].data, [4,null,2]);
    assert.equal(option.series[0].connectNulls, false);
    assert.deepEqual(option.xAxis.data, ['00:00 CEST','02:00 CET','03:00 CET']);
});
test('chart keeps integer metrics without smoothing or changing totals', () => {
    const option = lineOption([{label:'2026-10-24',pageviews:2},{label:'2026-10-25',pageviews:8}], 'pageviews');
    assert.deepEqual(option.series[0].data,[2,8]);
    assert.equal(option.series[0].smooth,false);
    assert.equal(option.yAxis.minInterval,1);
    assert.equal(option.tooltip.renderMode,'richText');
});

for (const [mode, background, text] of [['light', '#F8F8F8', '#00020C'], ['dark', '#00020C', '#F8F8F8']]) {
    test(`chart tooltips and labels follow the ${mode} theme`, () => {
        const option = lineOption([{label:'Today',pageviews:8}], 'pageviews', {background, text});
        assert.equal(option.tooltip.backgroundColor, background);
        assert.equal(option.tooltip.textStyle.color, text);
        assert.equal(option.xAxis.axisLabel.color, text);
        assert.equal(option.series[0].lineStyle.color, '#5516FF');
    });
}
