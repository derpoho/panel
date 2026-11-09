// Object.hasOwn is missing before Safari 15.4 and Chrome 93. Inertia's router
// clones page state with es-toolkit, which calls it, so without this every
// visit on those browsers fails with "Object.hasOwn is not a function".
if (typeof Object.hasOwn !== 'function') {
    Object.defineProperty(Object, 'hasOwn', {
        value: (object: object, key: PropertyKey): boolean =>
            Object.prototype.hasOwnProperty.call(Object(object), key),
        configurable: true,
        writable: true,
    });
}
