try {
    rs.status();
} catch (error) {
    rs.initiate({
        _id: 'phpforge-rs',
        members: [
            {_id: 0, host: 'mongodb-primary:27017', priority: 2},
            {_id: 1, host: 'mongodb-secondary:27017', priority: 1},
        ],
    });
}
