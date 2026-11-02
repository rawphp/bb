import { describe, expect, it } from 'vitest';
import { featureRoutes } from '@/app/router';
import { findScreen } from '../dev/screens';

describe('learn routes', () => {
    it.each(['learn_show', 'article_show'])('VIEW-001 /dev/screens/%s finds its Learn screen', (name) => {
        expect(findScreen(name, featureRoutes)).not.toBeNull();
    });
});
