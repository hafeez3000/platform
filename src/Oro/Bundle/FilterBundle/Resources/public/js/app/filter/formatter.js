var Oro = Oro || {};
Oro.Filter = Oro.Filter || {};

/**
 Just a convenient class for interested parties to subclass.

 The default Cell classes don't require the formatter to be a subclass of
 Formatter as long as the fromRaw(rawData) and toRaw(formattedData) methods
 are defined.

 @abstract
 @class Oro.Filter.Formatter
 @constructor
 */
Oro.Filter.Formatter = function () {};
_.extend(Oro.Filter.Formatter.prototype, {

    /**
     Takes a raw value from a model and returns a formatted string for display.

     @member Oro.Filter.Formatter
     @param {*} rawData
     @return {string}
     */
    fromRaw: function (rawData) {
        return rawData;
    },

    /**
     Takes a formatted string, usually from user input, and returns a
     appropriately typed value for persistence in the model.

     If the user input is invalid or unable to be converted to a raw value
     suitable for persistence in the model, toRaw must return `undefined`.

     @member Oro.Filter.Formatter
     @param {string} formattedData
     @return {*|undefined}
     */
    toRaw: function (formattedData) {
        return formattedData;
    }
});

/**
 A floating point number formatter. Doesn't understand notation at the moment.

 @class Oro.Filter.NumberFormatter
 @extends Oro.Filter.Formatter
 @constructor
 @throws {RangeError} If decimals < 0 or > 20.
 */
Oro.Filter.NumberFormatter = function (options) {
    options = options ? _.clone(options) : {};
    _.extend(this, this.defaults, options);

    if (this.decimals < 0 || this.decimals > 20) {
        throw new RangeError("decimals must be between 0 and 20");
    }
};
Oro.Filter.NumberFormatter.prototype = new Oro.Filter.Formatter;
_.extend(Oro.Filter.NumberFormatter.prototype, {

    /**
     @member Oro.Filter.NumberFormatter
     @cfg {Object} options

     @cfg {number} [options.decimals=2] Number of decimals to display. Must be an integer.

     @cfg {string} [options.decimalSeparator='.'] The separator to use when
     displaying decimals.

     @cfg {string} [options.orderSeparator=','] The separator to use to
     separator thousands. May be an empty string.
     */
    defaults: {
        decimals: 2,
        decimalSeparator: '.',
        orderSeparator: ','
    },

    HUMANIZED_NUM_RE: /(\d)(?=(?:\d{3})+$)/g,

    /**
     Takes a floating point number and convert it to a formatted string where
     every thousand is separated by `orderSeparator`, with a `decimal` number of
     decimals separated by `decimalSeparator`. The number returned is rounded
     the usual way.

     @member Oro.Filter.NumberFormatter
     @param {number} number
     @return {string}
     */
    fromRaw: function (number) {
        if (isNaN(number) || number === null) return '';

        number = number.toFixed(~~this.decimals);

        var parts = number.split('.');
        var integerPart = parts[0];
        var decimalPart = parts[1] ? (this.decimalSeparator || '.') + parts[1] : '';

        return integerPart.replace(this.HUMANIZED_NUM_RE, '$1' + this.orderSeparator) + decimalPart;
    },

    /**
     Takes a string, possibly formatted with `orderSeparator` and/or
     `decimalSeparator`, and convert it back to a number.

     @member Oro.Filter.NumberFormatter
     @param {string} formattedData
     @return {number|undefined} Undefined if the string cannot be converted to
     a number.
     */
    toRaw: function (formattedData) {
        var rawData = '';

        var thousands = formattedData.trim().split(this.orderSeparator);
        for (var i = 0; i < thousands.length; i++) {
            rawData += thousands[i];
        }

        var decimalParts = rawData.split(this.decimalSeparator);
        rawData = '';
        for (var i = 0; i < decimalParts.length; i++) {
            rawData = rawData + decimalParts[i] + '.';
        }

        if (rawData[rawData.length - 1] === '.') {
            rawData = rawData.slice(0, rawData.length - 1);
        }

        var result = (rawData * 1).toFixed(~~this.decimals) * 1;
        if (_.isNumber(result) && !_.isNaN(result)) return result;
    }
});
