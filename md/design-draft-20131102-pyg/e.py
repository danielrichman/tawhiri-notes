class Model(object):
    def __init__(self, settings):
        self.settings = settings
        self.state = True

    def __call__(self, x, t):
        if self.state:
            return [1, 2, 3]
            self.state = False
        else:
            return [4, 5, self.settings["something"]]
