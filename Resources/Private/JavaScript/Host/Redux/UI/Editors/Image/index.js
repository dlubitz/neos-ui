import {createAction} from 'redux-actions';
import {$get, $set, $drop} from 'plow-js';
import {Map} from 'immutable';



const TOGGLE_IMAGE_DETAILS_SCREEN = '@packagefactory/guevara/UI/Editors/Image/TOGGLE_IMAGE_DETAILS_SCREEN';
const UPDATE_IMAGE = '@packagefactory/guevara/UI/Editors/Image/UPDATE_IMAGE';


const toggleImageDetailsScreen = createAction(TOGGLE_IMAGE_DETAILS_SCREEN, (screenIdentifier) => ({screenIdentifier}));
const updateImage = createAction(UPDATE_IMAGE, (nodeContextPath, imageUuid, transientImage) => ({nodeContextPath, imageUuid, transientImage}));

//
// Export the actions
//
export const actions = {
    toggleImageDetailsScreen,
    updateImage
};

export const actionTypes = {
    TOGGLE_IMAGE_DETAILS_SCREEN,
    UPDATE_IMAGE
};

//
// Export the initial state
//
export const hydrate = () => new Map({
    visibleDetailsScreen: null
});


const IMAGE_DETAILS_SCREEN_PATH = 'ui.editors.image.visibleDetailsScreen';
//
// Export the reducer
//
export const reducer = {
    [TOGGLE_IMAGE_DETAILS_SCREEN]: ({screenIdentifier}) => state => {
        if ($get(IMAGE_DETAILS_SCREEN_PATH, state) === screenIdentifier) {
            return $set(IMAGE_DETAILS_SCREEN_PATH, null, state);
        }
        return $set(IMAGE_DETAILS_SCREEN_PATH, screenIdentifier, state);
    },
    [UPDATE_IMAGE]: ({nodeContextPath, imageUuid, transientImage}) => $set(['ui', 'inspector', 'valuesByNodePath', nodeContextPath, 'images', imageUuid], transientImage) // !!! DIFFERENT PATH -> in ui.inspector!!!
};
