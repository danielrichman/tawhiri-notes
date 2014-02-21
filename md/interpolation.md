# Wind Dataset Interpolation

## The problem:

Wind datasets have 5 axes: time, pressure, variable, latitude, longitude, where variable is “windu”, “windv” or “height”.

Given the current time, latitude, longitude and altitude we wish to interpolate and get an estimate of the two wind variables at that point.

## Method used by existing C & Daniel’s Python, Haskell proof of concepts:

Interpolating the time, latitude and longitude is easy enough: pick points either side of the target, and interpolate linearly.

So, having done that, suppose we have the height, windu and windv estimates at the current time, lat, lon, but for each of (the 47) pressure levels. We can now (binary, or otherwise) search through the heights to find two pressure levels where the heights are below and above the current altitude, and finally linearly interpolate between those for the final windu & windv.

If the altitude goes out of range, most implementations switch to linear extrapolation (basically by not requiring the lerp be in `[-1, 1]`—it’s the exact same calculation).

The only other axis that can go out of range is time; going out of range there throws an error.

## Implementation details

Note that the longitude axis wraps around.

The existing C interpolates each axis in turn: we have 8 points, then it interpolates along (say) the latitude axis to get 4 points, and so on. This is hidden in three nested functions; the outermost `interp_variable3` asks `interp_variable2` twice, for “before” and “after”, which in turn asks `interp_variable` for “below” and “above”; `interp_variable` considers `nw`, `ne`, `sw`, `se`; lerps along lat to get `w` and `e` and finally lerps along lon.

The C binary searches twice: once for the “before” and once for the “after”.

The C binary remembers what the last pressure levels were and tries them first before binary searching. Since we move slowly and continuously between levels, this “guess” is going to be correct most of the time and thereby amounts to a large (`log 47`≈5 times?—haven’t measured it) speedup.

In the explanation above, I said “suppose we have ... estimates for each of the levels”. We of course don’t need these estimates for all the levels. The Python grabs them all, because it’s a numpy vectorised operation and is easier to read (and I wasn’t concerned about speed at that point). The C and Haskell only retrieve the ones they need.

The Python and Haskell, unlike the C, when interpolating along time, lat, lon, instead of lerping along each axis in turn, just take the 8 combinations, multiply together the three lerp constants, and add them up in one go. This is (mathematically) equivalent.

## A quick tour of the Haskell 

`idxLerps3` picks the indices left and right of the desired points on the hour, lat, lon axes; returning a list of 8 `((time index, lat index, lon index), product of interpolation coefficients)`.

`interp3'` is `interp3` with the output of idxLerps3 partially applied for convenience

`interp3'` takes a variable and a pressure level, gets the 8 points `idxLerps3` said it should, and calculates `sum( product of lerps * value at the point )`.

`interpolate` then uses `search` on `interp3'` to find the desired pressure levels, grabs the lower and upper altitude, calculates the lerp.

`interp4'` does the final lerp (a two-liner for convenience); it has a couple of things partially applied and is then called twice; once for windu and once for windv.
