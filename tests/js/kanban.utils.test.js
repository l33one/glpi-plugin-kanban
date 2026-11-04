'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');

const { getOrderedStatusEntries } = require('../../public/js/kanban.utils.js');

const nativeStatuses = {
   1: { name: 'New' },
   2: { name: 'Assigned' },
   3: { name: 'Planned' },
   4: { name: 'Pending' },
   5: { name: 'Solved' },
   6: { name: 'Closed' },
};

function ids(entries) {
   return entries.map(([id]) => id);
}

// Regression for #5: Object.entries() enumerates integer-like keys in numeric
// order, so the board ignored the order configured by the administrator.
test('respects the configured order instead of the numeric key order', () => {
   assert.deepEqual(ids(getOrderedStatusEntries(nativeStatuses, [4, 3, 1, 2, 5, 6])), [
      '4',
      '3',
      '1',
      '2',
      '5',
      '6',
   ]);
   assert.deepEqual(ids(getOrderedStatusEntries(nativeStatuses, [6, 5, 4, 3, 2, 1])), [
      '6',
      '5',
      '4',
      '3',
      '2',
      '1',
   ]);
});

// GLPI 11 adds the approval status (10). It is not in the order saved by an
// installation upgraded from GLPI 10, but it must still get a column.
test('appends statuses missing from the configured order', () => {
   const statuses = { ...nativeStatuses, 10: { name: 'Approval' } };

   assert.deepEqual(ids(getOrderedStatusEntries(statuses, [2, 1])), ['2', '1', '3', '4', '5', '6', '10']);
   assert.deepEqual(ids(getOrderedStatusEntries(statuses, [1, 2, 3, 4, 5, 6])), [
      '1',
      '2',
      '3',
      '4',
      '5',
      '6',
      '10',
   ]);
});

test('falls back to the native order when no order is configured', () => {
   const expected = ['1', '2', '3', '4', '5', '6'];

   assert.deepEqual(ids(getOrderedStatusEntries(nativeStatuses, undefined)), expected);
   assert.deepEqual(ids(getOrderedStatusEntries(nativeStatuses, null)), expected);
   assert.deepEqual(ids(getOrderedStatusEntries(nativeStatuses, 'not-an-array')), expected);
   assert.deepEqual(ids(getOrderedStatusEntries(nativeStatuses, [])), expected);
});

test('ignores duplicate and unknown ids in the configured order', () => {
   assert.deepEqual(ids(getOrderedStatusEntries(nativeStatuses, [1, 1, 99, 2, '2'])), [
      '1',
      '2',
      '3',
      '4',
      '5',
      '6',
   ]);
});

test('handles an empty board without throwing', () => {
   assert.deepEqual(getOrderedStatusEntries({}, [1, 2]), []);
   assert.deepEqual(getOrderedStatusEntries(undefined, [1, 2]), []);
});

test('returns the status objects untouched', () => {
   const entries = getOrderedStatusEntries(nativeStatuses, [2]);
   const index = entries.findIndex(([id]) => id === '2');

   assert.equal(entries[index][1], nativeStatuses[2]);
   assert.deepEqual(Object.keys(nativeStatuses), ['1', '2', '3', '4', '5', '6']);
});
