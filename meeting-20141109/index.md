# A brief summary of the Predictor Hackathon (2014-11-09)

## Installing & Datasets

We tried to get the predictor set up and running on everyone’s laptops (or linux VMs inside laptops). It’s worth noting that you’ll need a 64 bit computer; several things larger than 4GB are involved.

We were successful in getting the Python installed.
The [README](https://github.com/cuspaceflight/tawhiri/blob/master/README.md) provides the basics.
You probably do not need to bother with the downloader, just the first section.
We’re nominally targeting Python3.3/3.4 but are so far compatible with Python2.

tl;dr `git checkout; virtualenv venv; source venv/bin/activate; pip install -r requirements.txt`

Next, you need two datasets; each is ~8GB:

 - elevation dataset (altitude of ground level for each point on earth)
 - wind dataset

Downloading these at the meeting proved difficult due to poor wifi.

You can grab a wind dataset from [this server](http://predict.habhub.org/datasets/) (pick the file named YYYYMMDDHH, not the “gribmirror”).
It’s big. If you get billed for lots of bandwidth usage at your college, you could download in CUED—they probably won’t care. Alternatively, transfer within Cambridge is probably free, and I can host that file from my desktop if necessary; just ask.

You can grab an elevation dataset by running the `ruaumoko-download` command, which will be available after having run `pip install -r requiements.txt` from above.
By default it will try to put it in `/srv`. You can tell it to put it somewhere else with the first command line argument, e.g. `ruaumoko-dataset ./ruaumoko-dataset`.
It’s compressed so actually comes to about a 1G download.

If downloading is a problem, the following substitutes may help:

  - [This 20MB file](http://predict.cusf.co.uk/datasets/archive/2014093006-UK) contains the wind data for the UK only from some random day I picked in September
    and [this program](http://predict.cusf.co.uk/datasets/archive/unpack.native) [(src)](https://github.com/danielrichman/tawhiri-tools/tree/master/dataset-archive) will unpack it into a “sparse file” that only uses 500MB on disk, but “looks like” a 8GB wind dataset with zeros everywhere that isn’t the UK.
    You will have a sad time if you run a prediction that leaves the UK on this dataset.
  - You can switch off ground-altitude-elevation-stuff landing and just have the prediction terminate at sea level instead. If you’re doing accuracy analysis, you probably don’t want to do this, because the difference is significant.
    [This diff](disable-elev.diff.html) will disable use of the elevation dataset.

## Running the predictor, and getting at the output

[This file, test_prediction.py](https://github.com/cuspaceflight/tawhiri/blob/master/testing/test_prediction.py) shows you how to invoke the predictor.

In reality, the predictor will be invoked via the [HTTP API](https://github.com/cuspaceflight/tawhiri/blob/master/tawhiri/api.py) which if you squint, looks the same-ish (it calls `models.standard_profile`, `solver.solve`, ...) except it uses parameters from the HTTP request, instead of hardcoded ones.
If you’re working on accuracy or live predictions then I assume you’re going to want to write a script that dumps a prediction to CSV, or computes some metric or whatever, and the `test_prediction.py` script would be a good starting point.

You need to tell it which wind dataset to use, and where to find it.
As shipped, it searches for the “latest” dataset in a magic directory. This is probably not what you want. [This diff](dataset-location.diff.html) shows you how to modify it to do what you want.

Finally, run `python3 testing/test_prediction.py` (or just “python”, if you’re using py2).

It produces two files in the current working directory, that is, the directory you were in when you ran the command.
One of them is a javascript file that just contains `var data = [list of lat, lon tuples];`
This is suitable for use with [this quick Google Maps hack](https://github.com/danielrichman/tawhiri-tools/tree/master/quick-map), which we were using before we had the proper UI.
The other is a kml file, which you can load into Google Earth.
It’s up to you what you use. Both of them are only really suitable for eyeballing.

If, say, you wanted to modify that test script to output to CSV so you can load it into MATLAB and play with it, you might do [something like this](csv.diff.html).

## How it works

If you want to work out how it works, following the functions `test_prediction.py` calls is probably a good start.

The [tawhiri docs](http://tawhiri.readthedocs.org/en/latest/predictor.html) summarise some of what we discussed.

I’m very happy to answer questions. Please do email if it’s preventing you from getting started! I’m also occasionally around CUED or the Computer Lab.

## Getting at specific datasets.

The above dataset you downloaded is perhaps not useful to what you’re doing if you want to compare it to a past balloon flight (will get to that in a second).

I’ve been saving a cut-out of the UK for the last few months, they’re [here](http://predict.cusf.co.uk/datasets/archive/), and [this program](http://predict.cusf.co.uk/datasets/archive/unpack.native) (as mentioned above) unpacks them

We’re going to (at some point) produce a predictor that can use the UK cut-outs directly, since this will be a lot faster if you want to automate or run predictions on many past datasets, and won’t use as much disk space (as mentioned above, they’re 500MB unpacked, due to overhead of sparse files).

## Analysis: comparing to balloon flights.

All of our balloons fly with trackers on them that report positions about once every 15 seconds. In fact, there’s a thriving amateur community in the UK that does similar flights with compatible trackers.
There’s a centralised system (with which CUSF has been fairly heavily involved) that collates submissions from people with radio receivers all over the country and stores them in a [database “habitat”](http://habitat.habhub.org/) and plots them on a map [“spacenear.us”](http://spacenear.us).

You can get at past data [here](http://habitat.habhub.org/ept):
It exports to CSV, JSON and KML. You could for example load a prediction and an exported KML trace into Google earth (only good for eyeball comparison).

Our last flight was “Nova 27”. If I search for that, select the JOEY payload, tick “time, latitude, longitude, altitude” and click CSV [I get this](nova27.csv).
The first point is 7km up since we weren’t uploading as we let go, which is unfortunate. Most flights will be complete.

At this point you’ll note that the file I mentioned above (`2014093006-UK`) wasn’t just some random day…
If we [set up the predictor](nova27-prediction.diff.html) to use this dataset, with the right initial conditions, divine knowledge of the ascent rate and burst altitude, and a guess at the right magic number to give the descent rate model…
then we get this [kml](nova27-prediction.kml) [jpg](nova27.jpg) [jpg2](nova27b.jpg).

If I use the 12Z dataset and start a couple of minutes in, I get [this](nova27-12z.jpg).

Not bad, but could be better, especially since I knew the exact burst alt.

If you want to do more interesting/automated things (say, look through the past few months of flights, identify which are in the UK, automatically retrieve the relevant wind UK datasets, run predictions…) you may want to interact with the database directly. This isn’t too bad; let me know.

## Live predictor

The live predictor runs on abovementioned [spacenear.us](http://spacenear.us/tracker/)
The tracker is a webpage, not created by CUSF, that uses the backend (“habitat”). The history of these things is quite long…

Basically, the data used above is available live; that’s the main point of the system.
Given a flight in progress, the live predictor attempts to infer

  - ascent/descent rate
  - whether it’s burst yet or not

and it’s given the burst altitude in advance.

This uses the old and busted predictor; we want to use the new one, and moreover, there are a /lot/ of improvements that could be made to the above “inference”.

[This PHP](predict.php.html) is the current “live predictor” and may induce nausea (it just sets up the things and dials out to the C binary).

Some ideas

  - rewrite in Python
  - efficiency: you can update a linear regression live
  - inference of descent rate: dubious. Perhaps want to transform it to match the shape of the model we have, and then linear regression? I don’t know.

Advanced ideas?

  - If you know the balloon type and payload weight, then you can infer the burst altitude from the ascent rate, and vice versa. People normally know the weight of their stuff fairly accurately, but /don’t/ know the ascent rate or burst altitude since accurately filling balloons with He is hard.
    However, after launch we /have/ data for the ascent rate, so could infer the (otherwise high variance) burst altitude from it.

To play, I suggest you ignore integration with the relevant databases and where to put the results for now. This requires knowledge of various existing systems and is probably going to be boring.

Instead, use the above mentioned stuff to acquire a flight, forget everything but the first N lines, and try and write something that would work as if it were run live.

## Misc

If you modify any .pxd files, you need to run the line mentioned in the README again:

    python setup.py build_ext --inplace
