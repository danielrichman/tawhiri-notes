class Model(object):
    def __init__(self, some_setting=6):
        self.some_setting = some_setting

    def __call__(self, x, t):
        return [4, self.some_setting, 0]
