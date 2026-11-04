/**
 * -------------------------------------------------------------------------
 * GLPI Kanban Plugin
 * -------------------------------------------------------------------------
 *
 * Pure helpers of the Kanban board.
 *
 * This file has no DOM dependency: the browser loads it before kanban.js and
 * the Node test runner requires it directly, so the board behaviour can be
 * covered without a browser.
 *
 * @package   Plugin Kanban
 * @author    Leewan Meneses
 * @license   GPL-2.0+
 * -------------------------------------------------------------------------
 */
(function (root, factory) {
   const api = factory();
   if (typeof module === 'object' && module.exports) {
      module.exports = api;
   } else {
      root.KanbanUtils = api;
   }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
   'use strict';

   /**
    * Order the statuses for the board columns.
    *
    * The configured order is a list of status ids. Ids that do not exist in
    * `statuses` are ignored, duplicates never repeat a column, and statuses
    * missing from the order (a status added by a newer GLPI) are appended in
    * native order so they never disappear from the board.
    *
    * @param {Object} statuses Map of status id => status data
    * @param {Array}  order    Configured status id order
    * @returns {Array<Array>}  List of [id, status] pairs
    */
   function getOrderedStatusEntries(statuses, order) {
      const source = statuses || {};
      const configured = Array.isArray(order) ? order : [];
      const entries = [];
      const seen = new Set();

      for (const rawId of configured) {
         const id = String(rawId);
         if (Object.prototype.hasOwnProperty.call(source, id) && !seen.has(id)) {
            entries.push([id, source[id]]);
            seen.add(id);
         }
      }

      for (const [id, status] of Object.entries(source)) {
         if (!seen.has(id)) {
            entries.push([id, status]);
         }
      }

      return entries;
   }

   return { getOrderedStatusEntries };
});
