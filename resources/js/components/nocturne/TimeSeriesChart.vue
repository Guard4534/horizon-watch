<script setup lang="ts">
import { useElementSize } from '@vueuse/core';
import { computed, ref, useTemplateRef } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { formatWait } from '@/lib/monitoring';
import {
    axisTicks,
    formatAxisCount,
    formatBucketTime,
    niceCountMax,
    niceSecondsMax,
    spansDays,
    timeTicks,
} from '@/lib/timeSeries';

const {
    values,
    secondary = null,
    startsAt,
    stepSeconds,
    height,
    label,
    describe,
    describeSecondary = null,
} = defineProps<{
    values: number[];
    secondary?: number[] | null;
    startsAt: string;
    stepSeconds: number;
    height: number;
    label: string;
    describe: (value: number) => string;
    describeSecondary?: ((value: number) => string) | null;
}>();

const TOP = 8;
const BOTTOM = 20;
const CHAR = 6.6;
const GAP = 6;
const FALLBACK_WIDTH = 480;

const { locale } = useLocale();
const box = useTemplateRef<HTMLDivElement>('box');
const { width: measured } = useElementSize(box);
const width = computed(() => Math.round(measured.value) || FALLBACK_WIDTH);
const hovered = ref<number | null>(null);

const from = computed(() => new Date(startsAt).getTime());
const lastIndex = computed(() => Math.max(values.length - 1, 1));
const until = computed(() => from.value + lastIndex.value * stepSeconds * 1000);
const withDay = computed(() => spansDays(lastIndex.value * stepSeconds));

const primaryMax = computed(() => niceCountMax(Math.max(0, ...values)));
const secondaryMax = computed(() =>
    secondary ? niceSecondsMax(Math.max(0, ...secondary)) : null,
);

const leftTicks = computed(() =>
    axisTicks(primaryMax.value).map((value) => ({
        value,
        text: formatAxisCount(value),
    })),
);
const rightTicks = computed(() =>
    secondaryMax.value === null
        ? []
        : axisTicks(secondaryMax.value).map((value) => ({
              value,
              text: formatWait(value),
          })),
);

let context: CanvasRenderingContext2D | null | undefined;

function textWidth(text: string): number {
    if (context === undefined && typeof document !== 'undefined') {
        context = document.createElement('canvas').getContext('2d');

        if (context) {
            context.font = `10px ${getComputedStyle(document.body).fontFamily}`;
        }
    }

    return context ? context.measureText(text).width : text.length * CHAR;
}

const axisWidth = (texts: string[]) =>
    texts.length === 0
        ? 0
        : Math.ceil(Math.max(...texts.map(textWidth))) + GAP + 2;

const left = computed(() => axisWidth(leftTicks.value.map((t) => t.text)));
const right = computed(() =>
    Math.max(axisWidth(rightTicks.value.map((t) => t.text)), 4),
);
const plotWidth = computed(() =>
    Math.max(width.value - left.value - right.value, 1),
);
const plotHeight = computed(() => Math.max(height - TOP - BOTTOM, 1));

const xAt = (index: number) =>
    left.value + (index / lastIndex.value) * plotWidth.value;
const yAt = (value: number, max: number) =>
    TOP + plotHeight.value - (value / max) * plotHeight.value;

function path(series: number[], max: number): string {
    return series
        .map(
            (value, index) =>
                `${index ? 'L' : 'M'}${xAt(index).toFixed(1)} ${yAt(value, max).toFixed(1)}`,
        )
        .join(' ');
}

const primaryLine = computed(() => path(values, primaryMax.value));
const primaryArea = computed(() =>
    values.length === 0
        ? ''
        : `${primaryLine.value} L${xAt(values.length - 1).toFixed(1)} ${TOP + plotHeight.value} L${left.value} ${TOP + plotHeight.value} Z`,
);
const secondaryLine = computed(() =>
    secondary && secondaryMax.value !== null
        ? path(secondary, secondaryMax.value)
        : '',
);

const xTicks = computed(() => {
    const spacing = withDay.value ? 76 : 60;
    const maxLabels = Math.min(
        7,
        Math.max(2, Math.floor(plotWidth.value / spacing)),
    );
    const span = until.value - from.value;

    return timeTicks(from.value, until.value, maxLabels, locale.value).map(
        (tick) => {
            const x =
                left.value + ((tick.at - from.value) / span) * plotWidth.value;
            const anchor =
                x - left.value < 18
                    ? 'start'
                    : left.value + plotWidth.value - x < 18
                      ? 'end'
                      : 'middle';

            return { ...tick, x, anchor };
        },
    );
});

const bucketTime = (index: number) =>
    formatBucketTime(
        new Date(from.value + index * stepSeconds * 1000),
        locale.value,
        withDay.value,
    );

const readout = computed(() => {
    const index = hovered.value;

    if (index === null || index >= values.length) {
        return null;
    }

    const x = xAt(index);
    const onLeft = x - left.value < plotWidth.value / 2;

    return {
        x,
        time: bucketTime(index),
        primary: describe(values[index]),
        primaryY: yAt(values[index], primaryMax.value),
        secondary:
            secondary && describeSecondary && index < secondary.length
                ? describeSecondary(secondary[index])
                : null,
        secondaryY:
            secondary && secondaryMax.value !== null && index < secondary.length
                ? yAt(secondary[index], secondaryMax.value)
                : null,
        style: onLeft
            ? { left: `${x + 10}px` }
            : { right: `${width.value - x + 10}px` },
    };
});

function track(event: PointerEvent): void {
    const bounds = (event.currentTarget as SVGElement).getBoundingClientRect();
    const offset = event.clientX - bounds.left - left.value;
    const index = Math.round((offset / plotWidth.value) * lastIndex.value);

    hovered.value =
        values.length === 0
            ? null
            : Math.min(Math.max(index, 0), values.length - 1);
}

function leave(event: PointerEvent): void {
    if (event.pointerType === 'mouse') {
        hovered.value = null;
    }
}
</script>

<template>
    <div ref="box" class="relative">
        <svg
            :width="width"
            :height="height"
            :viewBox="`0 0 ${width} ${height}`"
            role="img"
            :aria-label="label"
            class="block"
            style="touch-action: pan-y"
            @pointerdown="track"
            @pointermove="track"
            @pointerleave="leave"
            @pointercancel="hovered = null"
        >
            <g class="nc-num" style="font-size: 10px">
                <template v-for="tick in leftTicks" :key="`l${tick.value}`">
                    <line
                        :x1="left"
                        :x2="left + plotWidth"
                        :y1="yAt(tick.value, primaryMax)"
                        :y2="yAt(tick.value, primaryMax)"
                        stroke="var(--nc-divider)"
                        :stroke-opacity="tick.value === 0 ? 1 : 0.5"
                        stroke-width="1"
                        shape-rendering="crispEdges"
                    />
                    <text
                        :x="left - GAP"
                        :y="yAt(tick.value, primaryMax)"
                        text-anchor="end"
                        dominant-baseline="middle"
                        fill="var(--nc-neutral-500)"
                    >
                        {{ tick.text }}
                    </text>
                </template>
                <text
                    v-for="tick in rightTicks"
                    :key="`r${tick.value}`"
                    :x="left + plotWidth + GAP"
                    :y="yAt(tick.value, secondaryMax ?? 1)"
                    text-anchor="start"
                    dominant-baseline="middle"
                    fill="var(--st-warn)"
                    fill-opacity="0.75"
                >
                    {{ tick.text }}
                </text>
                <text
                    v-for="tick in xTicks"
                    :key="`x${tick.at}`"
                    :x="tick.x"
                    :y="height - 5"
                    :text-anchor="tick.anchor"
                    fill="var(--nc-neutral-500)"
                >
                    {{ tick.label }}
                </text>
            </g>
            <path
                :d="primaryArea"
                fill="var(--nc-accent)"
                opacity="0.12"
                stroke="none"
            />
            <path
                :d="primaryLine"
                fill="none"
                stroke="var(--nc-accent)"
                stroke-width="1.5"
                stroke-linejoin="round"
            />
            <path
                v-if="secondaryLine"
                :d="secondaryLine"
                fill="none"
                stroke="var(--st-warn)"
                stroke-width="1.5"
                stroke-linejoin="round"
            />
            <g v-if="readout" pointer-events="none">
                <line
                    :x1="readout.x"
                    :x2="readout.x"
                    :y1="TOP"
                    :y2="TOP + plotHeight"
                    stroke="var(--nc-neutral-500)"
                    stroke-width="1"
                    shape-rendering="crispEdges"
                />
                <circle
                    :cx="readout.x"
                    :cy="readout.primaryY"
                    r="3"
                    fill="var(--nc-accent)"
                    stroke="var(--nc-surface)"
                    stroke-width="1.5"
                />
                <circle
                    v-if="readout.secondaryY !== null"
                    :cx="readout.x"
                    :cy="readout.secondaryY"
                    r="3"
                    fill="var(--st-warn)"
                    stroke="var(--nc-surface)"
                    stroke-width="1.5"
                />
            </g>
        </svg>
        <div
            v-if="readout"
            class="nc-num pointer-events-none absolute flex flex-col"
            :style="{
                ...readout.style,
                top: `${TOP}px`,
                gap: '2px',
                padding: '5px 8px',
                fontSize: '11px',
                whiteSpace: 'nowrap',
                background: 'var(--nc-bg)',
                borderRadius: 'var(--nc-radius-sm)',
                boxShadow: 'var(--nc-shadow-md)',
            }"
        >
            <span style="color: var(--nc-neutral-400)">{{ readout.time }}</span>
            <span class="inline-flex items-center gap-[5px]">
                <span
                    class="h-[2px] w-[10px]"
                    style="background: var(--nc-accent)"
                />{{ readout.primary }}
            </span>
            <span
                v-if="readout.secondary !== null"
                class="inline-flex items-center gap-[5px]"
            >
                <span
                    class="h-[2px] w-[10px]"
                    style="background: var(--st-warn)"
                />{{ readout.secondary }}
            </span>
        </div>
    </div>
</template>
