import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { TodayShowOutput } from '@/contracts/today_show';
import type { WeighInsShowOutput } from '@/contracts/weigh_ins_show';
import { screenFixture } from '@/testing/schemas';
import TodayWeighInCard from './TodayWeighInCard.vue';

const trend = screenFixture<WeighInsShowOutput>('weigh_ins_show').trend;
const noTrend = screenFixture<WeighInsShowOutput>('weigh_ins_show.empty').trend;
const weighIn = screenFixture<TodayShowOutput>('today_show').weigh_ins[0]!;

describe('TodayWeighInCard', () => {
    it('WT-011 shows today\'s weight and the trend weight', () => {
        const card = mount(TodayWeighInCard, { props: { weighIn, trend } });

        expect(card.get('h3').text()).toBe('Weigh-in');
        expect(card.text()).toContain('82.4 kg');
        expect(card.text()).toContain('Trend weight 83.0 kg');
        expect(card.find('button').exists()).toBe(false);
    });

    it('WT-002 with no weigh-in today it offers Add weigh-in', async () => {
        const card = mount(TodayWeighInCard, { props: { weighIn: { ...weighIn, weight_text: null }, trend: noTrend } });

        await card.get('button').trigger('click');

        expect(card.get('button').text()).toBe('Add weigh-in');
        expect(card.text()).toContain('Trend weight No weigh-ins yet');
        expect(card.emitted('add')).toHaveLength(1);
    });

    it('OFF-008 marks the weight and trend as waiting to sync', () => {
        const card = mount(TodayWeighInCard, { props: { weighIn, trend, pending: true } });

        expect(card.text()).toContain('Waiting to sync');
    });
});
